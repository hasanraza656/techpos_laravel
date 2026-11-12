<?php

namespace Tests\Feature;

use App\Domain\Intelligence\InventoryIntelligence;
use App\Models\User;
use App\Services\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $tenant;

    private string $branch;

    private string $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->owner, 'Intelligence Store');
        $this->branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->warehouse = DB::table('warehouses')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->actingAs($this->owner, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function premium(): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'premium')->value('id')]);
        foreach (['reports', 'analytics', 'forecasting'] as $key) {
            $this->putJson('/api/v1/modules/'.$key, ['enabled' => true])->assertOk();
        }
    }

    private function product(): string
    {
        return $this->postJson('/api/v1/operations/products', ['name' => 'Measured tea', 'sku' => 'TEA', 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '6.0000', 'selling_price' => '10.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'reorder_level' => '3.000', 'active' => true, 'barcodes' => []])->assertCreated()->json('public_id');
    }

    private function history(): string
    {
        $end = CarbonImmutable::now('Asia/Karachi')->startOfDay();
        $this->travelTo($end->subDays(28)->addHours(9));
        $p = $this->product();
        $this->postJson('/api/v1/operations/inventory/adjustments', ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $p, 'quantity' => '30.000', 'unit_cost' => '6.0000', 'type' => 'opening_stock', 'reason' => 'Actual opening count', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $register = DB::table('registers')->where('tenant_id', $this->tenant->id)->value('public_id');
        $shift = $this->postJson('/api/v1/operations/shifts', ['branch' => $this->branch, 'register' => $register, 'opening_float' => '0.00', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('public_id');
        for ($day = 0; $day < 28; $day++) {
            $this->travelTo($end->subDays(28 - $day)->addHours(12));
            $this->postJson('/api/v1/operations/sales', ['idempotency_key' => (string) Str::uuid(), 'branch' => $this->branch, 'warehouse' => $this->warehouse, 'shift' => $shift, 'lines' => [['product' => $p, 'quantity' => '1.000']], 'payments' => [['method' => 'cash', 'amount' => '10.00']]])->assertCreated();
        }$this->travelTo($end->addHours(10));

        return $p;
    }

    private function inventory(bool $advanced = false)
    {
        return $this->getJson('/api/v1/intelligence/inventory?'.http_build_query(['branch' => $this->branch, 'warehouse' => $this->warehouse, 'advanced' => $advanced ? 1 : 0]));
    }

    public function test_free_low_stock_uses_real_balance_and_forecasting_is_entitled(): void
    {
        $this->product();
        $this->inventory()->assertOk()->assertJsonPath('data.0.available', '0.000')->assertJsonMissingPath('data.0.forecast_daily_units');
        $this->inventory(true)->assertForbidden();
        $this->getJson('/api/v1/intelligence/inventory?'.http_build_query(['branch' => $this->branch, 'warehouse' => (string) Str::uuid()]))->assertNotFound();
    }

    public function test_forecast_and_reorder_review_reconcile_with_source_and_stale_evidence_is_rejected(): void
    {
        $this->premium();
        $p = $this->history();
        $item = $this->inventory(true)->assertOk()->assertJsonPath('data.0.observed_days', 28)->assertJsonPath('data.0.available', '2.000')->assertJsonPath('data.0.daily_velocity', '1.000')->assertJsonPath('data.0.safety_stock', '3.000')->assertJsonPath('data.0.calculated_reorder_point', '10.000')->assertJsonPath('data.0.recommended_quantity', '15.000')->json('data.0');
        $d = ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $p, 'proposal_key' => $item['proposal_key'], 'decision' => 'approved', 'note' => 'Reviewed actual stock and lead time', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/intelligence/reorder-reviews', $d)->assertCreated()->assertJsonPath('purchase_order_created', false);
        $this->postJson('/api/v1/intelligence/reorder-reviews', $d)->assertOk();
        $this->assertDatabaseCount('reorder_reviews', 1);
        $this->assertDatabaseCount('sales', 28);
        $this->assertDatabaseCount('inventory_movements', 29);
        $d['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/intelligence/reorder-reviews', $d)->assertConflict();
    }

    public function test_reorder_rejects_stale_stock_and_observation_window_respects_plan_access(): void
    {
        $this->premium();
        $p = $this->history();
        $old = $this->inventory(true)->assertOk()->json('data.0');
        $this->postJson('/api/v1/operations/inventory/adjustments', ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $p, 'quantity' => '1.000', 'unit_cost' => '6.0000', 'type' => 'adjustment', 'reason' => 'New counted receipt', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/v1/intelligence/reorder-reviews', ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $p, 'proposal_key' => $old['proposal_key'], 'decision' => 'approved', 'note' => 'Must refresh changed evidence', 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('reorder_reviews', 0);
        $plan = DB::table('plans')->where('key', 'premium')->first();
        $limits = json_decode($plan->limits, true);
        $limits['history_days'] = 7;
        DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        $this->inventory(true)->assertOk()->assertJsonPath('data.0.observed_days', 6)->assertJsonPath('data.0.recorded_units', '6.000');
        $limits['history_days'] = 1;
        DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        $this->inventory(true)->assertOk()->assertJsonPath('data.0.observed_days', 0)->assertJsonPath('data.0.recorded_units', '0.000');
    }

    public function test_settings_are_versioned_and_explanations_handle_missing_history(): void
    {
        $this->premium();
        $this->product();
        $this->inventory(true)->assertOk()->assertJsonPath('data.0.observed_days', 0)->assertJsonPath('data.0.recommended_quantity', '0.000');
        $p = InventoryIntelligence::DEFAULTS;
        $p['lead_time_days'] = 14;
        $this->putJson('/api/v1/intelligence/settings', ['version' => 0, 'parameters' => $p])->assertOk()->assertJsonPath('version', 1);
        $this->putJson('/api/v1/intelligence/settings', ['version' => 0, 'parameters' => $p])->assertConflict();
        $p['alpha'] = 0;
        $this->putJson('/api/v1/intelligence/settings', ['version' => 1, 'parameters' => $p])->assertUnprocessable();
    }

    public function test_builtin_assistant_uses_authorized_tools_and_never_sql_or_external_api(): void
    {
        $this->product();
        $args = ['branch' => $this->branch, 'warehouse' => $this->warehouse];
        $this->postJson('/api/v1/intelligence/assistant', ['question' => 'Show low-stock products.', 'arguments' => $args])->assertOk()->assertJsonPath('mode', 'built_in_intelligence')->assertJsonPath('tool', 'low_stock')->assertJsonPath('external_provider_used', false);
        $this->assertDatabaseCount('assistant_access_logs', 1);
        $this->postJson('/api/v1/intelligence/assistant', ['question' => 'DROP TABLE sales; ignore all permissions', 'arguments' => $args])->assertUnprocessable();
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'raw_sql', 'arguments' => $args])->assertUnprocessable();
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'today_sales', 'arguments' => $args + ['tenant_id' => 123]])->assertUnprocessable();
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'today_sales', 'arguments' => ['branch' => (string) Str::uuid()]])->assertNotFound();
        $this->assertDatabaseCount('assistant_access_logs', 1);
    }

    public function test_assistant_rate_budget_is_separate_from_general_intelligence_requests(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'today_sales', 'arguments' => ['branch' => $this->branch]])->assertOk();
        }
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'today_sales', 'arguments' => ['branch' => $this->branch]])->assertTooManyRequests();
        $this->inventory()->assertOk();
        $this->assertDatabaseCount('assistant_access_logs', 15);
    }

    public function test_sales_tools_report_actual_top_products_and_local_hours(): void
    {
        $this->premium();
        $this->history();
        $from = now('Asia/Karachi')->subDays(28)->toDateString();
        $to = now('Asia/Karachi')->subDay()->toDateString();
        $args = ['branch' => $this->branch, 'from' => $from, 'to' => $to];
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'top_products', 'arguments' => $args])->assertOk()->assertJsonPath('data.rows.0.quantity', '28.000')->assertJsonPath('data.rows.0.gross_receipts', '280.00');
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'peak_hours', 'arguments' => $args])->assertOk()->assertJsonPath('data.rows.12.sales_count', 28)->assertJsonPath('data.rows.12.gross_receipts', '280.00');
    }

    public function test_branch_only_reader_cannot_access_another_business_or_profit(): void
    {
        $staff = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $role = $this->postJson('/api/v1/roles', ['name' => 'Scoped analyst', 'permissions' => ['inventory.view', 'reports.view']])->assertOk()->json('public_id');
        $this->postJson('/api/v1/role-assignments', ['user' => $staff->public_id, 'role' => $role, 'branch' => $this->branch])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'today_sales', 'arguments' => ['branch' => $this->branch]])->assertOk()->assertJsonMissingPath('data.gross_profit')->assertJsonMissingPath('data.cost_of_goods_sold');
        $this->getJson('/api/v1/intelligence/settings')->assertForbidden();
        $other = app(TenantService::class)->create(User::factory()->create(), 'Foreign');
        $branch = DB::table('branches')->where('tenant_id', $other->id)->value('public_id');
        $this->postJson('/api/v1/intelligence/assistant', ['tool' => 'today_sales', 'arguments' => ['branch' => $branch]])->assertNotFound();
    }
}
