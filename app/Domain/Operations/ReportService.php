<?php

namespace App\Domain\Operations;

use App\Services\Entitlements;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReportService
{
    public function range(TenantContext $c, array $data): array
    {
        $today = CarbonImmutable::now($c->tenant->timezone)->startOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $c->tenant->timezone)->startOfDay() : $today;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $c->tenant->timezone)->startOfDay() : $from;
        if ($to->lt($from) || $from->diffInDays($to) > 365) {
            Decimal::fail('to', 'Choose an ordered range of at most 366 days.');
        }
        $days = app(Entitlements::class)->plan($c->tenant->id)->limits['history_days'] ?? 30;
        if ($from->lt($today->subDays(max(0, $days - 1)))) {
            Decimal::fail('from', 'This date is outside your plan history access. Historical data is preserved.');
        }

        return [$from->utc(), $to->addDay()->utc()];
    }

    public function summary(TenantContext $c, object $branch, array $data): array
    {
        abort_unless($branch->tenant_id === $c->tenant->id, 404);
        $c->authorize('reports.view', $branch->id);
        app(Entitlements::class)->authorize($c->tenant->id, 'daily_summary');
        [$from,$until] = $this->range($c, $data);
        if ($until->diffInHours($from, true) > 25) {
            app(Entitlements::class)->authorize($c->tenant->id, 'reports');
        }
        $sales = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('finalized_at', '>=', $from)->where('finalized_at', '<', $until);
        $sums = ['total' => '0.00', 'tax_total' => '0.00', 'line_discount' => '0.00', 'cart_discount' => '0.00', 'change_due' => '0.00', 'cost_total' => '0.0000'];
        $count = 0;
        foreach ((clone $sales)->select(array_keys($sums))->cursor() as $sale) {
            $count++;
            foreach ($sums as $field => $value) {
                $sums[$field] = bcadd($value, (string) $sale->$field, $field === 'cost_total' ? 4 : 2);
            }
        }
        $returns = DB::table('sale_returns')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $refund = '0.00';
        $refundTax = '0.00';
        $reverse = '0.0000';
        $returnCount = 0;
        $creditReturns = '0.00';
        foreach ($returns->cursor() as $r) {
            $returnCount++;
            $refund = bcadd($refund, (string) $r->total, 2);
            $creditReturns = bcadd($creditReturns, (string) $r->credit_amount, 2);
            $refundTax = bcadd($refundTax, (string) $r->tax_total, 2);
            $reverse = bcadd($reverse, (string) $r->cost_reversed, 4);
        }
        $payments = ['cash' => '0.00', 'external_card' => '0.00', 'bank_transfer' => '0.00', 'credit' => '0.00'];
        foreach (DB::table('sale_payments as p')->joinSub((clone $sales)->select('id'), 's', 's.id', '=', 'p.sale_id')->where('p.tenant_id', $c->tenant->id)->select('p.method', 'p.amount')->cursor() as $p) {
            $payments[$p->method] = bcadd($payments[$p->method] ?? '0.00', (string) $p->amount, 2);
        }
        $creditSales = $payments['credit'];
        // Returns refund the khata first; only the remainder left the drawer as cash.
        $payments['cash'] = bcsub(bcsub($payments['cash'], $sums['change_due'], 2), bcsub($refund, $creditReturns, 2), 2);
        $payments['credit'] = bcsub($payments['credit'], $creditReturns, 2);
        $collections = '0.00';
        $collectionsCash = '0.00';
        foreach (DB::table('customer_ledger')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('type', 'payment')->where('created_at', '>=', $from)->where('created_at', '<', $until)->select('method', 'amount')->cursor() as $p) {
            $amount = bcsub('0', (string) $p->amount, 2);
            $collections = bcadd($collections, $amount, 2);
            if ($p->method === 'cash') {
                $collectionsCash = bcadd($collectionsCash, $amount, 2);
            }
        }
        $net = bcsub($sums['total'], $refund, 2);
        $tax = bcsub($sums['tax_total'], $refundTax, 2);
        $revenue = bcsub($net, $tax, 2);
        $result = ['from_utc' => $from->toIso8601String(), 'until_utc' => $until->toIso8601String(), 'timezone' => $c->tenant->timezone, 'currency' => $c->tenant->currency, 'sales_count' => $count, 'returns_count' => $returnCount, 'gross_receipts' => $sums['total'], 'cash_refunds' => $refund, 'net_receipts' => $net, 'tax_net' => $tax, 'revenue_ex_tax' => $revenue, 'sale_discounts' => bcadd($sums['line_discount'], $sums['cart_discount'], 2), 'payments_net' => $payments, 'credit_sales' => $creditSales, 'credit_returns' => $creditReturns, 'credit_collections' => $collections, 'credit_collections_cash' => $collectionsCash, 'note' => 'Sales and return events are attributed to their own posting date. Profit uses recorded weighted-average sale cost, reversed only for restocked goods; it excludes business expenses and is not a full profit-and-loss statement.'];
        if ($c->allows('reports.view_profit', $branch->id)) {
            $cost = bcsub($sums['cost_total'], $reverse, 4);
            $result['cost_of_goods_sold'] = $cost;
            $result['gross_profit'] = bcsub($revenue, $cost, 4);
        }

        return $result;
    }
}
