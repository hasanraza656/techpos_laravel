<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\Decimal;
use App\Domain\Operations\OperationScope;
use App\Domain\Operations\ReportService;
use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ReportingTools
{
    public const TOOLS = ['today_sales', 'low_stock', 'reorder', 'top_products', 'peak_hours', 'sales_comparison'];

    public function run(TenantContext $c, string $tool, array $arguments): array
    {
        abort_unless(in_array($tool, self::TOOLS, true), 422, 'This analytical intent is not supported. Choose an available report.');
        if (array_diff(array_keys($arguments), ['branch', 'warehouse', 'from', 'to'])) {
            Decimal::fail('arguments', 'Only registered report arguments are allowed.');
        }
        $d = Validator::make($arguments, ['branch' => 'required|uuid', 'warehouse' => in_array($tool, ['low_stock', 'reorder'], true) ? 'required|uuid' : 'nullable|uuid', 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d'])->validate();
        if (in_array($tool, ['low_stock', 'reorder'], true) && empty($d['warehouse'])) {
            Decimal::fail('warehouse', 'Choose a stockroom.');
        }
        app(Entitlements::class)->authorize($c->tenant->id, 'builtin_intelligence');
        $start = microtime(true);
        $count = 0;
        try {
            if (in_array($tool, ['low_stock', 'reorder'], true)) {
                $result = app(InventoryIntelligence::class)->inventory($c, $d + ['advanced' => $tool === 'reorder', 'per_page' => 25]);
                $count = count($result['data']);
                $narrative = $tool === 'reorder' ? 'Statistical recommendations based on recorded demand and configured lead/safety parameters. Reviews do not create purchases.' : 'Products at or below their reorder level, using the selected stockroom.';
                $data = $result;
            } else {
                $branch = (new OperationScope($c))->branch($d['branch'], 'reports.view');
                $reports = app(ReportService::class);
                $summary = $reports->summary($c, $branch, $d);
                $count = $summary['sales_count'];
                $narrative = 'Totals come from authorized finalized sales and separate cash-return records.';
                if ($tool === 'today_sales') {
                    $data = $summary;
                } elseif ($tool === 'sales_comparison') {
                    app(Entitlements::class)->authorize($c->tenant->id, 'reports');
                    [$from,$until] = $reports->range($c, $d);
                    $days = (int) $from->setTimezone($c->tenant->timezone)->diffInDays($until->setTimezone($c->tenant->timezone), true);
                    $previousFrom = $from->setTimezone($c->tenant->timezone)->subDays($days)->toDateString();
                    $previousTo = $from->setTimezone($c->tenant->timezone)->subDay()->toDateString();
                    $previous = $reports->summary($c, $branch, ['from' => $previousFrom, 'to' => $previousTo]);
                    $change = bcsub($summary['net_receipts'], $previous['net_receipts'], 2);
                    $percentage = bccomp($previous['net_receipts'], '0', 2) === 0 ? null : Decimal::round(bcdiv(bcmul($change, '100', 8), $previous['net_receipts'], 8));
                    $data = ['current' => $summary, 'previous' => $previous, 'net_receipts_change' => $change, 'change_percent' => $percentage];
                    $narrative = 'Compared with the preceding equal-length local-date period. Percentage is unavailable when the previous net receipts were zero.';
                } else {
                    app(Entitlements::class)->authorize($c->tenant->id, 'reports');
                    [$from,$until] = $reports->range($c, $d);
                    $sales = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('finalized_at', '>=', $from)->where('finalized_at', '<', $until);
                    if ($tool === 'top_products') {
                        $rows = DB::table('sale_lines as l')->joinSub($sales->select('id'), 's', 's.id', '=', 'l.sale_id')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.tenant_id', $c->tenant->id)->select('p.public_id as product', 'l.name')->selectRaw('SUM(l.quantity) as quantity,SUM(l.total) as gross_receipts')->groupBy('p.public_id', 'l.name')->orderByRaw('SUM(l.quantity) DESC')->limit(10)->get()->map(fn ($r) => ['product' => $r->product, 'name' => $r->name, 'quantity' => bcadd((string) $r->quantity, '0', 3), 'gross_receipts' => bcadd((string) $r->gross_receipts, '0', 2)]);
                        $data = ['rows' => $rows, 'from' => $from->toIso8601String(), 'until_exclusive' => $until->toIso8601String(), 'currency' => $c->tenant->currency];
                        $count = count($rows);
                        $narrative = 'Top ten product snapshots by finalized sale quantity. Gross receipts include item tax and discounts, before subsequent returns; renamed products may have multiple name snapshots.';
                    } else {
                        $hours = array_map(fn ($h) => ['hour' => str_pad((string) $h, 2, '0', STR_PAD_LEFT).':00', 'sales_count' => 0, 'gross_receipts' => '0.00'], range(0, 23));
                        foreach ($sales->select('finalized_at', 'total')->cursor() as $sale) {
                            $h = (int) CarbonImmutable::parse($sale->finalized_at, 'UTC')->setTimezone($c->tenant->timezone)->format('G');
                            $hours[$h]['sales_count']++;
                            $hours[$h]['gross_receipts'] = bcadd($hours[$h]['gross_receipts'], (string) $sale->total, 2);
                        }$data = ['rows' => $hours, 'timezone' => $c->tenant->timezone, 'currency' => $c->tenant->currency];
                        $narrative = 'Finalized sale counts and gross receipts by business-local hour, before subsequent returns.';
                    }
                }
            }
            DB::table('assistant_access_logs')->insert(['public_id' => (string) Str::uuid(), 'tenant_id' => $c->tenant->id, 'user_id' => $c->user->id, 'branch_id' => DB::table('branches')->where('tenant_id', $c->tenant->id)->where('public_id', $d['branch'])->value('id'), 'tool' => $tool, 'arguments' => json_encode($d), 'result_count' => $count, 'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'created_at' => now()]);

            return ['mode' => 'built_in_intelligence', 'tool' => $tool, 'explanation' => $narrative, 'data' => $data, 'external_provider_used' => false];
        } catch (\Throwable $e) {
            Audit::record('intelligence.tool_denied_or_failed', $c->tenant->id, $tool, ['code' => (string) $e->getCode()]);
            throw $e;
        }
    }

    public function recognize(string $question): array
    {
        $q = mb_strtolower(trim($question));
        if (preg_match('/\b(reorder|replenish)\b/u', $q)) {
            $tool = 'reorder';
        } elseif (preg_match('/low[ -]stock|running out|stock alerts/u', $q)) {
            $tool = 'low_stock';
        } elseif (preg_match('/top\s*(10|ten)?\s*products|best[ -]selling/u', $q)) {
            $tool = 'top_products';
        } elseif (preg_match('/peak|shopping hours|busy hours/u', $q)) {
            $tool = 'peak_hours';
        } elseif (preg_match('/compare|comparison/u', $q)) {
            $tool = 'sales_comparison';
        } elseif (preg_match('/sell today|sales today|today.?s sales|sales summary|weekly.*summary/u', $q)) {
            $tool = 'today_sales';
        } else {
            Decimal::fail('question', 'Built-in mode supports sales summaries, low stock, reorder, top products, peak hours and period comparisons. Choose one of these reports; no external model is configured.');
        }

        return [$tool, str_contains($q, 'last month') ? 'last_month' : (str_contains($q, 'weekly') || str_contains($q, 'this week') ? 'last_7_days' : 'today')];
    }
}
