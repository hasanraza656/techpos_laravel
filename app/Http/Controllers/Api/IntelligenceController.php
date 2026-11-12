<?php

namespace App\Http\Controllers\Api;

use App\Domain\Intelligence\InventoryIntelligence;
use App\Domain\Intelligence\ReportingTools;
use App\Domain\Operations\OperationScope;
use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntelligenceController extends Controller
{
    public function assistant(Request $r, TenantContext $c, ReportingTools $tools)
    {
        $d = $r->validate(['question' => 'required_without:tool|prohibits:tool|string|max:500', 'tool' => 'required_without:question|prohibits:question|in:'.implode(',', ReportingTools::TOOLS), 'arguments' => 'required|array:branch,warehouse,from,to', 'arguments.branch' => 'required|uuid', 'arguments.warehouse' => 'nullable|uuid', 'arguments.from' => 'sometimes|date_format:Y-m-d', 'arguments.to' => 'sometimes|date_format:Y-m-d']);
        $arguments = $d['arguments'];
        if (isset($d['question'])) {
            [$tool,$period] = $tools->recognize($d['question']);
            $today = CarbonImmutable::now($c->tenant->timezone)->startOfDay();
            if (! isset($arguments['from'])) {
                $arguments['from'] = $period === 'last_month' ? $today->subMonthNoOverflow()->startOfMonth()->toDateString() : ($period === 'last_7_days' ? $today->subDays(6)->toDateString() : $today->toDateString());
                $arguments['to'] = $period === 'last_month' ? $today->startOfMonth()->subDay()->toDateString() : $today->toDateString();
            }
        } else {
            $tool = $d['tool'];
        }

return $tools->run($c, $tool, $arguments);
    }

    public function inventory(Request $r, TenantContext $c, InventoryIntelligence $s)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'warehouse' => 'required|uuid', 'advanced' => 'sometimes|boolean', 'product' => 'sometimes|uuid', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return $s->inventory($c, $d);
    }

    public function settings(TenantContext $c, InventoryIntelligence $s)
    {
        $c->authorize('settings.manage');

        return $s->settings($c->tenant->id);
    }

    public function saveSettings(Request $r, TenantContext $c, InventoryIntelligence $s)
    {
        $c->authorize('settings.manage');
        app(Entitlements::class)->authorize($c->tenant->id, 'forecasting');
        $d = $r->validate(['version' => 'required|integer|min:0', 'parameters' => 'required|array:lookback_days,lead_time_days,safety_days,review_days,overstock_days,dead_stock_days,service_factor,alpha,method', 'parameters.lookback_days' => 'required|integer|min:7|max:90', 'parameters.lead_time_days' => 'required|integer|min:0|max:180', 'parameters.safety_days' => 'required|integer|min:0|max:180', 'parameters.review_days' => 'required|integer|min:1|max:90', 'parameters.overstock_days' => 'required|integer|min:1|max:365', 'parameters.dead_stock_days' => 'required|integer|min:7|max:90', 'parameters.service_factor' => 'required|numeric|min:0|max:4', 'parameters.alpha' => 'required|numeric|gt:0|lte:1', 'parameters.method' => 'required|in:moving_average,exponential_smoothing']);

        return DB::transaction(function () use ($d, $c, $s) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $current = $s->settings($c->tenant->id);
            abort_unless($current['version'] === $d['version'], 409, 'Intelligence settings changed. Reload before saving.');
            DB::table('intelligence_settings')->updateOrInsert(['tenant_id' => $c->tenant->id], ['parameters' => json_encode($d['parameters']), 'version' => $d['version'] + 1, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('intelligence.settings_changed', $c->tenant->id, null, ['version' => $d['version'] + 1]);

            return $s->settings($c->tenant->id);
        });
    }

    public function review(Request $r, TenantContext $c, InventoryIntelligence $s)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'warehouse' => 'required|uuid', 'product' => 'required|uuid', 'proposal_key' => 'required|string|size:64|regex:/^[a-f0-9]+$/', 'decision' => 'required|in:approved,rejected', 'note' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        $scope = new OperationScope($c);
        $b = $scope->branch($d['branch'], 'inventory.adjust');
        $scope->warehouse($d['warehouse'], $b->id);
        app(Entitlements::class)->authorize($c->tenant->id, 'forecasting');

        return DB::transaction(function () use ($d, $c, $s, $b) {
            DB::table('branches')->where('id', $b->id)->lockForUpdate()->first();
            $old = DB::table('reorder_reviews')->where('tenant_id', $c->tenant->id)->where('proposal_key', $d['proposal_key'])->first();
            if ($old) {
                abort_unless($old->public_id === $d['idempotency_key'] && $old->user_id === $c->user->id && $old->decision === $d['decision'] && $old->note === $d['note'], 409, 'This recommendation already has a final review.');

                return ['public_id' => $old->public_id, 'decision' => $old->decision, 'purchase_order_created' => false];
            }$item = $s->inventory($c, $d + ['advanced' => true, 'per_page' => 1])['data'][0] ?? null;
            abort_unless($item, 404);
            abort_unless(hash_equals($item['proposal_key'], $d['proposal_key']), 409, 'The recommendation changed. Refresh current evidence before reviewing.');
            $product = DB::table('products')->where('tenant_id', $c->tenant->id)->where('public_id', $d['product'])->value('id');
            $warehouse = DB::table('warehouses')->where('tenant_id', $c->tenant->id)->where('public_id', $d['warehouse'])->value('id');
            DB::table('reorder_reviews')->insert(['public_id' => $d['idempotency_key'], 'tenant_id' => $c->tenant->id, 'branch_id' => $b->id, 'warehouse_id' => $warehouse, 'product_id' => $product, 'user_id' => $c->user->id, 'proposal_key' => $d['proposal_key'], 'decision' => $d['decision'], 'note' => $d['note'], 'evidence' => json_encode($item), 'created_at' => now()]);
            Audit::record('intelligence.recommendation_reviewed', $c->tenant->id, $d['idempotency_key'], ['decision' => $d['decision'], 'product' => $d['product']]);

            return response()->json(['public_id' => $d['idempotency_key'], 'decision' => $d['decision'], 'purchase_order_created' => false],201);
        }, 3);
    }
}
