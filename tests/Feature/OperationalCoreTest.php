<?php

namespace Tests\Feature;

use App\Domain\Operations\Decimal;
use App\Domain\Operations\SaleCalculator;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalCoreTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $tenant;

    private string $branch;

    private string $warehouse;

    private string $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->owner, 'Core Store');
        $this->branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->warehouse = DB::table('warehouses')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->register = DB::table('registers')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->actingAs($this->owner, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function product(string $sku = 'SKU1', array $extra = []): string
    {
        return $this->postJson('/api/v1/operations/products', array_replace(['name' => 'Tea '.$sku, 'sku' => $sku, 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '60.0000', 'selling_price' => '100.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'reorder_level' => '2.000', 'active' => true, 'barcodes' => [['barcode' => 'BAR'.$sku, 'quantity' => '1.000']]], $extra))->assertCreated()->json('public_id');
    }

    private function stock(string $product, string $quantity = '10.000', string $cost = '60.0000'): array
    {
        $data = ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $product, 'quantity' => $quantity, 'unit_cost' => $cost, 'type' => 'opening_stock', 'reason' => 'Initial count', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/operations/inventory/adjustments', $data)->assertCreated();

        return $data;
    }

    private function shift(): string
    {
        return $this->postJson('/api/v1/operations/shifts', ['branch' => $this->branch, 'register' => $this->register, 'opening_float' => '1000.00', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('public_id');
    }

    private function sale(string $product, string $shift, array $extra = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'branch' => $this->branch, 'warehouse' => $this->warehouse, 'shift' => $shift, 'lines' => [['product' => $product, 'quantity' => '2.000', 'expected_unit_price' => '100.00']], 'cart_discount' => '0.00', 'payments' => [['method' => 'cash', 'amount' => '500.00']]], $extra);
    }

    public function test_cash_sale_deducts_once_and_shift_reconciles_with_change(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $payload = $this->sale($p, $shift);
        $sale = $this->postJson('/api/v1/operations/sales', $payload)->assertCreated()->assertJsonPath('total', '200.00')->assertJsonPath('change_due', '300.00')->assertJsonPath('cost_total', '120.0000')->json('public_id');
        $this->postJson('/api/v1/operations/sales', $payload)->assertCreated()->assertJsonPath('public_id', $sale);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertEquals('8.000', bcadd((string) DB::table('stock_balances')->value('quantity'), '0', 3));
        $payload['payments'][0]['amount'] = '600.00';
        $this->postJson('/api/v1/operations/sales', $payload)->assertConflict();
        $this->postJson('/api/v1/operations/shifts/'.$shift.'/close', ['actual_cash' => '1190.00', 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonFragment(['cash_difference' => '-10.00']);
    }

    public function test_insufficient_stock_rolls_back_entire_multi_product_sale(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');
        $this->stock($a, '10.000');
        $shift = $this->shift();
        $payload = $this->sale($a, $shift, ['lines' => [['product' => $a, 'quantity' => '1.000'], ['product' => $b, 'quantity' => '1.000']]]);
        $this->postJson('/api/v1/operations/sales', $payload)->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(10, DB::table('stock_balances')->where('product_id', DB::table('products')->where('public_id', $a)->value('id'))->value('quantity'));
    }

    public function test_weighted_average_cost_inclusive_tax_and_split_external_recording(): void
    {
        $p = $this->product('W', ['type' => 'weighted', 'selling_price' => '112.00', 'tax_rate' => '12.0000', 'tax_inclusive' => true]);
        $this->stock($p, '2.000', '40.0000');
        $this->stock($p, '2.000', '80.0000');
        $shift = $this->shift();
        $payload = $this->sale($p, $shift, ['lines' => [['product' => $p, 'quantity' => '1.500']], 'payments' => [['method' => 'cash', 'amount' => '100.00'], ['method' => 'external_card', 'amount' => '68.00', 'reference' => 'TERMINAL-001']]]);
        $this->postJson('/api/v1/operations/sales', $payload)->assertCreated()->assertJsonPath('total', '168.00')->assertJsonPath('tax_total', '18.00')->assertJsonPath('cost_total', '90.0000')->assertJsonPath('payments.1.verification', 'externally_recorded');
        $this->assertDatabaseHas('stock_balances', ['quantity' => '2.500', 'cost_value' => '150.0000']);
    }

    public function test_adjustment_retry_barcode_uniqueness_product_version_and_plan_limit(): void
    {
        $p = $this->product();
        $adjust = $this->stock($p);
        $this->postJson('/api/v1/operations/inventory/adjustments', $adjust)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->postJson('/api/v1/operations/products', ['name' => 'Invalid'])->assertUnprocessable();
        $result = $this->getJson('/api/v1/operations/products?'.http_build_query(['branch' => $this->branch, 'warehouse' => $this->warehouse, 'barcode' => 'BARSKU1']))->assertOk()->assertJsonPath('data.0.selling_price', '100.00')->json('data');
        $this->assertCount(1, $result);
        $plan = DB::table('plans')->where('key', 'free')->first();
        $limits = json_decode($plan->limits, true);
        $limits['active_skus'] = 1;
        DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        $this->postJson('/api/v1/operations/products', ['name' => 'Other', 'sku' => 'OTHER', 'type' => 'service', 'unit' => 'hour', 'cost_price' => '0.0000', 'selling_price' => '100.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'reorder_level' => '0.000', 'active' => true, 'barcodes' => []])->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_tenant_branch_permissions_and_cost_redaction(): void
    {
        $p = $this->product();
        $this->stock($p);
        $staff = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first();
        DB::table('user_role_assignments')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'role_id' => $role->id, 'branch_id' => DB::table('branches')->where('public_id', $this->branch)->value('id')]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web')->getJson('/api/v1/operations/products?'.http_build_query(['branch' => $this->branch, 'warehouse' => $this->warehouse]))->assertOk()->assertJsonMissingPath('data.0.cost_price');
        $this->postJson('/api/v1/operations/inventory/adjustments', ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $p, 'quantity' => '1.000', 'unit_cost' => '1.0000', 'type' => 'opening_stock', 'reason' => 'Denied edit', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $other = app(TenantService::class)->create(User::factory()->create(), 'Other');
        $foreign = DB::table('warehouses')->where('tenant_id', $other->id)->value('public_id');
        $this->getJson('/api/v1/operations/products?'.http_build_query(['branch' => $this->branch, 'warehouse' => $foreign]))->assertNotFound();
    }

    public function test_history_is_database_immutable_and_cash_events_are_idempotent(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $event = ['amount' => '50.00', 'type' => 'cash_out', 'reason' => 'Petty cash', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/operations/shifts/'.$shift.'/cash-events', $event)->assertCreated();
        $this->postJson('/api/v1/operations/shifts/'.$shift.'/cash-events', $event)->assertCreated();
        $this->assertDatabaseCount('cash_events', 1);
        $this->expectException(QueryException::class);
        DB::table('inventory_movements')->update(['quantity' => '999']);
    }

    public function test_disabled_pos_and_discount_permissions_are_backend_enforced(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $feature = DB::table('features')->where('key', 'pos')->value('id');
        DB::table('tenant_feature_overrides')->insert(['tenant_id' => $this->tenant->id, 'feature_id' => $feature, 'enabled' => false]);
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift))->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_decimal_rounding_and_cart_discount_allocation_are_exact(): void
    {
        $calc = app(SaleCalculator::class)->calculate([['quantity' => '1.000', 'unit_price' => '0.10', 'tax_rate' => '10.0000', 'tax_inclusive' => false], ['quantity' => '1.000', 'unit_price' => '0.20', 'tax_rate' => '10.0000', 'tax_inclusive' => false]], '0.01');
        $this->assertSame('0.30', $calc['subtotal']);
        $this->assertSame('0.32', $calc['total']);
        $this->assertSame('0.01', Decimal::sum(array_column($calc['lines'], 'cart_discount')));
    }

    public function test_offline_sync_survives_retries_sequence_conflicts_and_device_revocation(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices', ['device_identifier' => $device, 'name' => 'Offline counter', 'schema_version' => 1])->assertOk();
        $payload = $this->sale($p, $shift, ['channel' => 'offline', 'device_identifier' => $device, 'cashier' => $this->owner->public_id, 'local_sequence' => 1, 'payload_version' => 1, 'client_occurred_at' => now()->toIso8601String(), 'expected_total' => '200.00']);
        $this->postJson('/api/v1/operations/sales', $payload)->assertCreated();
        $this->postJson('/api/v1/operations/sales', $payload)->assertCreated();
        $duplicate = $payload;
        $duplicate['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/operations/sales', $duplicate)->assertConflict();
        DB::table('devices')->where('device_identifier', $device)->update(['revoked_at' => now()]);
        $duplicate['local_sequence'] = 2;
        $this->postJson('/api/v1/operations/sales', $duplicate)->assertForbidden();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stock_balances', ['quantity' => '8.000']);
    }

    public function test_sync_batch_returns_partial_acknowledgments_without_losing_valid_records(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $valid = $this->sale($p, $shift);
        $conflict = $this->sale($p, $shift, ['expected_total' => '1.00']);
        $response = $this->postJson('/api/v1/operations/sync', ['records' => [$valid, $conflict, ['invalid' => true], $valid]])->assertOk();
        $response->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.1.code', 409)->assertJsonPath('results.2.code', 422)->assertJsonPath('results.3.sale.public_id', $valid['idempotency_key']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_price_conflicts_closed_shifts_and_foreign_customer_roll_back_cleanly(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $payload = $this->sale($p, $shift);
        DB::table('products')->where('public_id', $p)->update(['selling_price' => '101.00']);
        $this->postJson('/api/v1/operations/sales', $payload)->assertConflict();
        $payload['lines'][0]['expected_unit_price'] = '101.00';
        $payload['customer'] = (string) Str::uuid();
        $this->postJson('/api/v1/operations/sales', $payload)->assertNotFound();
        unset($payload['customer']);
        $this->postJson('/api/v1/operations/shifts/'.$shift.'/close', ['actual_cash' => '1000.00', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/operations/sales', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stock_balances', ['quantity' => '10.000']);
    }

    public function test_zero_total_sale_and_wholesale_pricing_use_authoritative_totals(): void
    {
        $p = $this->product('FREE', ['selling_price' => '0.00']);
        $this->stock($p);
        $shift = $this->shift();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, ['lines' => [['product' => $p, 'quantity' => '1.000']], 'payments' => [['method' => 'cash', 'amount' => '0.00']]]))->assertCreated()->assertJsonPath('total', '0.00');
        $customer = $this->postJson('/api/v1/operations/customers', ['branch' => $this->branch, 'name' => 'Wholesale buyer', 'price_tier' => 'wholesale', 'marketing_consent' => false])->assertCreated()->json('public_id');
        DB::table('products')->where('public_id', $p)->update(['selling_price' => '100.00', 'wholesale_price' => '80.00']);
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, ['customer' => $customer, 'lines' => [['product' => $p, 'quantity' => '2.000', 'expected_unit_price' => '80.00']], 'expected_total' => '160.00']))->assertCreated()->assertJsonPath('total', '160.00');
    }

    private function enableReturns(): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'standard')->value('id')]);
        DB::table('tenant_feature_overrides')->insert(['tenant_id' => $this->tenant->id, 'feature_id' => DB::table('features')->where('key', 'returns')->value('id'), 'enabled' => true]);
    }

    public function test_cash_return_reverses_exact_cost_once_and_reports_reconcile(): void
    {
        $this->enableReturns();
        $p = $this->product('TAX', ['selling_price' => '112.00', 'tax_rate' => '12.0000', 'tax_inclusive' => true]);
        $this->stock($p);
        $shift = $this->shift();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, ['lines' => [['product' => $p, 'quantity' => '1.000']]]))->assertCreated()->json('public_id');
        $refund = ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Customer returned sealed item', 'lines' => [['product' => $p, 'quantity' => '1.000', 'restock' => true]]];
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertStatus(201)->assertJsonPath('total', '112.00')->assertJsonPath('cost_reversed', '60.0000');
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertCreated();
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseHas('stock_balances', ['quantity' => '10.000', 'cost_value' => '600.0000']);
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch)->assertOk()->assertJsonPath('gross_receipts', '112.00')->assertJsonPath('cash_refunds', '112.00')->assertJsonPath('tax_net', '0.00')->assertJsonPath('cost_of_goods_sold', '0.0000')->assertJsonPath('gross_profit', '0.0000')->assertJsonPath('payments_net.cash', '0.00');
        $refund['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertUnprocessable();
        $this->getJson('/api/v1/operations/sales/'.$sale)->assertOk()->assertJsonPath('total', '112.00');
        $this->postJson('/api/v1/operations/shifts/'.$shift.'/close', ['actual_cash' => '1000.00', 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('cash_difference', '0.00');
    }

    public function test_partial_return_rounding_caps_and_nonrestocked_cost_do_not_inflate_stock(): void
    {
        $this->enableReturns();
        $p = $this->product('MICRO', ['type' => 'weighted', 'selling_price' => '0.01', 'cost_price' => '0.3333']);
        $this->stock($p, '3.000', '0.3333');
        $shift = $this->shift();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, ['lines' => [['product' => $p, 'quantity' => '3.000']], 'payments' => [['method' => 'cash', 'amount' => '0.03']]]))->assertCreated()->json('public_id');
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Partial quantity inspection', 'lines' => [['product' => $p, 'quantity' => '0.500', 'restock' => $i > 0]]])->assertCreated();
        }
        $this->assertSame('0.03', Decimal::sum(DB::table('sale_returns')->pluck('total')));
        $this->assertSame('0.9999', Decimal::sum(DB::table('sale_return_lines')->pluck('cost_allocated'), 4));
        $this->assertDatabaseHas('stock_balances', ['quantity' => '2.500', 'cost_value' => '0.8332']);
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch)->assertOk()->assertJsonPath('net_receipts', '0.00')->assertJsonPath('cost_of_goods_sold', '0.1667')->assertJsonPath('gross_profit', '-0.1667');
    }

    public function test_return_entitlements_manual_external_restrictions_and_history_limit(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, ['payments' => [['method' => 'external_card', 'amount' => '200.00', 'reference' => 'External confirmed']]]))->assertCreated()->json('public_id');
        $refund = ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Return requested', 'lines' => [['product' => $p, 'quantity' => '1.000', 'restock' => true]]];
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertForbidden();
        $this->enableReturns();
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->travel(400)->days();
        $this->getJson('/api/v1/operations/sales/'.$sale)->assertForbidden();
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch.'&from='.now()->subDays(400)->toDateString())->assertUnprocessable();
    }

    public function test_branch_report_permissions_hide_cost_and_prevent_refunds(): void
    {
        $this->enableReturns();
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift))->assertCreated()->json('public_id');
        $staff = User::factory()->create();
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->value('id');
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $branchId = DB::table('branches')->where('public_id', $this->branch)->value('id');
        DB::table('user_role_assignments')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'role_id' => $role, 'branch_id' => $branchId]);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('key', 'reports.view')->value('id')]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch)->assertOk()->assertJsonPath('net_receipts', '200.00')->assertJsonMissingPath('cost_of_goods_sold')->assertJsonMissingPath('gross_profit');
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Unauthorized return', 'lines' => [['product' => $p, 'quantity' => '1.000', 'restock' => true]]])->assertForbidden();
        $foreign = app(TenantService::class)->create(User::factory()->create(), 'Foreign');
        $foreignBranch = DB::table('branches')->where('tenant_id', $foreign->id)->value('public_id');
        $this->getJson('/api/v1/operations/reports/summary?branch='.$foreignBranch)->assertNotFound();
    }

    public function test_cash_credit_note_is_immutable_and_reused_key_conflicts(): void
    {
        $this->enableReturns();
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift))->assertCreated()->json('public_id');
        $refund = ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Original reason', 'lines' => [['product' => $p, 'quantity' => '1.000', 'restock' => true]]];
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertCreated();
        $refund['reason'] = 'Different reason';
        $this->postJson('/api/v1/operations/sales/'.$sale.'/returns', $refund)->assertConflict();
        $this->expectException(QueryException::class);
        DB::table('sale_returns')->delete();
    }

    public function test_shared_device_cashier_streams_are_separate_and_original_actor_is_required(): void
    {
        $p = $this->product();
        $this->stock($p);
        $shift = $this->shift();
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices', ['device_identifier' => $device, 'name' => 'Shared counter', 'schema_version' => 1])->assertOk();
        $payload = $this->sale($p, $shift, ['device_identifier' => $device, 'cashier' => $this->owner->public_id, 'local_sequence' => 1]);
        $this->postJson('/api/v1/operations/sales', $payload)->assertCreated();
        $staff = User::factory()->create();
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->value('id');
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $branchId = DB::table('branches')->where('public_id', $this->branch)->value('id');
        DB::table('user_role_assignments')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'role_id' => $role, 'branch_id' => $branchId]);
        $register = (string) Str::uuid();
        DB::table('registers')->insert(['public_id' => $register, 'tenant_id' => $this->tenant->id, 'branch_id' => $branchId, 'name' => 'Shared second register', 'active' => true]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->postJson('/api/v1/operations/sales', $payload)->assertForbidden();
        $staffShift = $this->postJson('/api/v1/operations/shifts', ['branch' => $this->branch, 'register' => $register, 'opening_float' => '0.00', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('public_id');
        $this->postJson('/api/v1/devices', ['device_identifier' => $device, 'name' => 'Shared counter', 'schema_version' => 1])->assertOk();
        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['cashier'] = $staff->public_id;
        $payload['shift'] = $staffShift;
        $this->postJson('/api/v1/operations/sales', $payload)->assertCreated();
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseHas('stock_balances', ['quantity' => '6.000']);
    }

    public function test_catalog_editor_can_preserve_hidden_cost_but_cannot_change_wholesale_prices(): void
    {
        $p = $this->product();
        $staff = User::factory()->create();
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Branch Manager')->value('id');
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        DB::table('user_role_assignments')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'role_id' => $role]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $body = $this->getJson('/api/v1/operations/products/'.$p)->assertOk()->assertJsonMissingPath('cost_price')->json();
        $body['name'] = 'Renamed tea';
        $body['tax_inclusive'] = false;
        $body['active'] = true;
        $version = $this->putJson('/api/v1/operations/products/'.$p, $body)->assertOk()->json('version');
        $this->assertDatabaseHas('products', ['public_id' => $p, 'name' => 'Renamed tea', 'cost_price' => '60.0000']);
        $body['version'] = $version;
        $body['wholesale_price'] = '1.00';
        $this->putJson('/api/v1/operations/products/'.$p, $body)->assertForbidden();
        $this->assertDatabaseHas('products', ['public_id' => $p, 'wholesale_price' => null]);
    }
}
