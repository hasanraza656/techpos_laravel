<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $tenant;

    private string $branch;

    private int $branchId;

    private string $warehouse;

    private string $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create(['name' => 'Owner Person']);
        $this->tenant = app(TenantService::class)->create($this->owner, 'Khata Store');
        $this->branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->branchId = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('id');
        $this->warehouse = DB::table('warehouses')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->register = DB::table('registers')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->actingAs($this->owner, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function product(string $sku = 'SKU1'): string
    {
        $p = $this->postJson('/api/v1/operations/products', ['name' => 'Rice '.$sku, 'sku' => $sku, 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '60.0000', 'selling_price' => '100.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'reorder_level' => '0.000', 'active' => true, 'barcodes' => []])->assertCreated()->json('public_id');
        $this->postJson('/api/v1/operations/inventory/adjustments', ['branch' => $this->branch, 'warehouse' => $this->warehouse, 'product' => $p, 'quantity' => '50.000', 'unit_cost' => '60.0000', 'type' => 'opening_stock', 'reason' => 'Initial count', 'idempotency_key' => (string) Str::uuid()])->assertCreated();

        return $p;
    }

    private function shift(?string $register = null, string $float = '1000.00'): string
    {
        return $this->postJson('/api/v1/operations/shifts', ['branch' => $this->branch, 'register' => $register ?? $this->register, 'opening_float' => $float, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('public_id');
    }

    private function customer(string $limit = '1000.00', string $name = 'Bilal Traders'): string
    {
        return $this->postJson('/api/v1/operations/customers', ['branch' => $this->branch, 'name' => $name, 'phone' => '0300'.random_int(1000000, 9999999), 'price_tier' => 'retail', 'marketing_consent' => false, 'credit_limit' => $limit])->assertCreated()->assertJsonPath('credit_limit', $limit)->assertJsonPath('balance', '0.00')->json('public_id');
    }

    private function sale(string $product, string $shift, array $payments, array $extra = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'branch' => $this->branch, 'warehouse' => $this->warehouse, 'shift' => $shift, 'lines' => [['product' => $product, 'quantity' => '2.000', 'expected_unit_price' => '100.00']], 'payments' => $payments], $extra);
    }

    private function pay(string $customer, array $body)
    {
        return $this->postJson("/api/v1/operations/customers/$customer/payments", array_replace(['branch' => $this->branch, 'method' => 'cash', 'amount' => '50.00', 'idempotency_key' => (string) Str::uuid()], $body));
    }

    private function adjust(string $customer, array $body)
    {
        return $this->postJson("/api/v1/operations/customers/$customer/ledger-adjustments", array_replace(['type' => 'adjustment', 'amount' => '10.00', 'note' => 'Correction agreed', 'idempotency_key' => (string) Str::uuid()], $body));
    }

    private function balance(string $customer): string
    {
        return bcadd((string) DB::table('customers')->where('public_id', $customer)->value('balance'), '0', 2);
    }

    private function expected(string $shift): string
    {
        return $this->getJson('/api/v1/operations/bootstrap')->assertOk()->json('shift.expected_cash');
    }

    /** A staff member whose single role holds exactly these permissions in the branch. */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);
        $role = DB::table('roles')->insertGetId(['tenant_id' => $this->tenant->id, 'public_id' => (string) Str::uuid(), 'name' => 'Custom '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('permissions')->whereIn('key', $permissions)->pluck('id') as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('user_role_assignments')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role_id' => $role, 'branch_id' => $this->branchId]);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function staffShift(): string
    {
        $register = (string) Str::uuid();
        DB::table('registers')->insert(['public_id' => $register, 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branchId, 'name' => 'Counter '.Str::random(4), 'active' => true]);

        return $this->shift($register, '0.00');
    }

    private function enableReturns(): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'standard')->value('id')]);
        DB::table('tenant_feature_overrides')->insert(['tenant_id' => $this->tenant->id, 'feature_id' => DB::table('features')->where('key', 'returns')->value('id'), 'enabled' => true]);
    }

    public function test_feature_is_on_every_plan_and_exposed_in_bootstrap(): void
    {
        $feature = DB::table('features')->where('key', 'customer_credit')->first();
        $this->assertSame(['customers'], json_decode($feature->dependencies, true));
        foreach (['free', 'standard', 'premium'] as $plan) {
            $this->assertDatabaseHas('feature_entitlements', ['plan_id' => DB::table('plans')->where('key', $plan)->value('id'), 'feature_id' => $feature->id]);
        }
        $boot = $this->getJson('/api/v1/operations/bootstrap')->assertOk()->assertJsonPath('modules.customer_credit', true);
        foreach (['customers.credit_sell', 'customers.collect', 'customers.credit_manage'] as $key) {
            $this->assertContains($key, $boot->json('branches.0.permissions'));
        }
        $roles = DB::table('roles as r')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('r.tenant_id', $this->tenant->id)->where('p.key', 'like', 'customers.%')->get(['r.name', 'p.key'])->groupBy('name')->map(fn ($g) => $g->pluck('key')->sort()->values()->all());
        $this->assertSame(['customers.collect', 'customers.credit_manage', 'customers.credit_sell'], $roles['Branch Manager']);
        $this->assertSame(['customers.collect', 'customers.credit_sell'], $roles['Cashier']);
        $this->assertSame(['customers.collect', 'customers.credit_sell'], $roles['Sales Manager']);
        $this->assertContains('customers.credit_manage', $roles['Business Admin']);
    }

    public function test_credit_sale_updates_balance_ledger_and_document(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer('1000.00');
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated()
            ->assertJsonPath('total', '200.00')->assertJsonPath('change_due', '0.00')
            ->assertJsonPath('payments.0.method', 'credit')->assertJsonPath('payments.0.verification', 'customer_credit')
            ->assertJsonPath('customer.public_id', $customer)->assertJsonPath('customer.balance_before', '0.00')->assertJsonPath('customer.balance_after', '200.00')->json('public_id');
        $this->assertSame('200.00', $this->balance($customer));
        $entry = DB::table('customer_ledger')->sole();
        $this->assertSame('credit_sale', $entry->type);
        $this->assertSame('200.00', bcadd((string) $entry->amount, '0', 2));
        $this->assertSame(DB::table('sales')->where('public_id', $sale)->value('id'), $entry->sale_id);
        $this->getJson("/api/v1/operations/sales/$sale")->assertOk()->assertJsonPath('customer.balance_after', '200.00')->assertJsonPath('customer.balance_before', '0.00');
        // A second credit sale shows the running balance on its receipt.
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated()
            ->assertJsonPath('customer.balance_before', '200.00')->assertJsonPath('customer.balance_after', '400.00');
        // Cash sales for a customer do not carry khata balances.
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'cash', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated()->assertJsonPath('customer.balance_after', null);
        $this->getJson('/api/v1/operations/customers?branch='.$this->branch)->assertOk()->assertJsonPath('data.0.balance', '400.00')->assertJsonPath('data.0.credit_limit', '1000.00');
        // Credit sales add nothing to the drawer.
        $this->assertSame('1200.00', $this->expected($shift));
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch)->assertOk()->assertJsonPath('credit_sales', '400.00')->assertJsonPath('payments_net.credit', '400.00')->assertJsonPath('payments_net.cash', '200.00');
    }

    public function test_credit_needs_customer_cannot_exceed_remaining_total_and_gives_no_change(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer('1000.00');
        $post = fn (array $payments) => $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, $payments, ['customer' => $customer]));
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']]))->assertUnprocessable()->assertJsonValidationErrors('payments');
        $post([['method' => 'credit', 'amount' => '250.00']])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $post([['method' => 'cash', 'amount' => '100.00'], ['method' => 'credit', 'amount' => '150.00']])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $post([['method' => 'cash', 'amount' => '200.00'], ['method' => 'credit', 'amount' => '0.00']])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $post([['method' => 'credit', 'amount' => '100.00'], ['method' => 'credit', 'amount' => '100.00']])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_ledger', 0);
        // Partial: cash part enters the drawer, the rest goes on the khata.
        $post([['method' => 'cash', 'amount' => '50.00'], ['method' => 'credit', 'amount' => '150.00']])->assertCreated()->assertJsonPath('change_due', '0.00')->assertJsonPath('customer.balance_after', '150.00');
        $this->assertSame('150.00', $this->balance($customer));
        $this->assertSame('1050.00', $this->expected($shift));
    }

    public function test_zero_limit_disables_credit_and_over_limit_is_blocked(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $off = $this->customer('0.00', 'No credit');
        $res = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $off, 'credit_override_reason' => 'Owner approved it']))->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertSame('Credit is not enabled for this customer.', $res->json('errors.payments.0'));
        $small = $this->customer('150.00', 'Small limit');
        $res = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $small]))->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $this->assertStringContainsString('150.00', $res->json('errors.credit_limit.0'));
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_ledger', 0);
        $this->assertSame('0.00', $this->balance($small));
    }

    public function test_over_limit_override_needs_credit_manage_and_reason_and_is_audited(): void
    {
        $p = $this->product();
        $customer = $this->customer('150.00');
        $cashier = $this->staff(['sales.create', 'sales.view', 'customers.credit_sell']);
        $this->actAs($cashier);
        $shift = $this->staffShift();
        $res = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer, 'credit_override_reason' => 'Regular customer']))->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $this->assertStringContainsString('150.00', $res->json('errors.credit_limit.0'));
        // Within the limit the cashier may sell on credit.
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '100.00']], ['customer' => $customer, 'lines' => [['product' => $p, 'quantity' => '1.000']]]))->assertCreated();
        $this->actAs($this->owner);
        $ownerShift = $this->shift();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $ownerShift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $ownerShift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer, 'credit_override_reason' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('credit_override_reason');
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $ownerShift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer, 'credit_override_reason' => 'Known wholesaler, pays monthly']))->assertCreated()->assertJsonPath('customer.balance_after', '300.00');
        $this->assertSame('300.00', $this->balance($customer));
        $this->assertDatabaseHas('customer_ledger', ['type' => 'credit_sale', 'override_reason' => 'Known wholesaler, pays monthly']);
        $audit = DB::table('audit_logs')->where('action', 'customer.credit_override')->sole();
        $this->assertSame($customer, $audit->subject);
        $this->assertSame('Known wholesaler, pays monthly', json_decode($audit->metadata, true)['reason']);
        $this->assertSame($this->owner->id, $audit->actor_id);
    }

    public function test_offline_credit_sale_is_rejected_while_offline_cash_works(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer();
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices', ['device_identifier' => $device, 'name' => 'Counter', 'schema_version' => 1])->assertOk();
        $offline = fn (array $payments, int $seq) => $this->sale($p, $shift, $payments, ['customer' => $customer, 'channel' => 'offline', 'device_identifier' => $device, 'cashier' => $this->owner->public_id, 'local_sequence' => $seq, 'payload_version' => 1, 'client_occurred_at' => now()->toIso8601String()]);
        $res = $this->postJson('/api/v1/operations/sales', $offline([['method' => 'credit', 'amount' => '200.00']], 1))->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertSame('Credit sales need a connection.', $res->json('errors.payments.0'));
        $this->postJson('/api/v1/operations/sync', ['records' => [$offline([['method' => 'cash', 'amount' => '50.00'], ['method' => 'credit', 'amount' => '150.00']], 1)]])->assertOk()->assertJsonPath('results.0.code', 422)->assertJsonPath('results.0.errors.payments.0', 'Credit sales need a connection.');
        $this->postJson('/api/v1/operations/sales', $offline([['method' => 'cash', 'amount' => '200.00']], 1))->assertCreated();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customer_ledger', 0);
    }

    public function test_collections_enter_the_shift_drawer_and_validate_method_reference_and_balance(): void
    {
        DB::table('tenants')->where('id', $this->tenant->id)->update(['timezone' => 'Asia/Karachi']);
        // 23:10 UTC on Nov 24 is already Nov 25 for the business (UTC+5).
        $this->travelTo(Carbon::parse('2026-11-24 23:10:00', 'UTC'));
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated();
        $this->pay($customer, ['amount' => '50.00'])->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->pay($customer, ['amount' => '250.00', 'shift' => $shift])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($customer, ['amount' => '0.00', 'shift' => $shift])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($customer, ['amount' => '-5.00', 'shift' => $shift])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($customer, ['method' => 'bank_transfer', 'amount' => '20.00'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->pay($customer, ['method' => 'cheque', 'amount' => '20.00'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $key = (string) Str::uuid();
        $payment = $this->pay($customer, ['amount' => '50.00', 'shift' => $shift, 'idempotency_key' => $key, 'note' => 'Paid at counter'])->assertCreated()
            ->assertJsonPath('payment.amount', '50.00')->assertJsonPath('payment.method', 'cash')->assertJsonPath('payment.balance_before', '200.00')->assertJsonPath('payment.balance_after', '150.00')
            ->assertJsonPath('payment.customer.public_id', $customer)->assertJsonPath('payment.branch', DB::table('branches')->where('id', $this->branchId)->value('name'))->json('payment');
        $this->assertSame('TP-PAY-261125-'.strtoupper(str_replace('-', '', $payment['public_id'])), $payment['receipt_number']);
        $this->pay($customer, ['method' => 'bank_transfer', 'amount' => '30.00', 'reference' => 'IBFT-778812'])->assertCreated()->assertJsonPath('payment.balance_after', '120.00')->assertJsonPath('payment.reference', 'IBFT-778812');
        $this->pay($customer, ['method' => 'external_card', 'amount' => '20.00', 'reference' => 'POS-4411', 'shift' => $shift])->assertCreated()->assertJsonPath('payment.balance_after', '100.00');
        $this->assertSame('100.00', $this->balance($customer));
        $entry = DB::table('customer_ledger')->where('idempotency_key', $key)->first();
        $this->assertSame('-50.00', bcadd((string) $entry->amount, '0', 2));
        $this->assertSame(DB::table('register_shifts')->where('public_id', $shift)->value('id'), $entry->shift_id);
        // Only the cash collection enters the drawer.
        $this->assertSame('1050.00', $this->expected($shift));
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch)->assertOk()
            ->assertJsonPath('credit_sales', '200.00')->assertJsonPath('credit_collections', '100.00')->assertJsonPath('credit_collections_cash', '50.00');
        $this->postJson("/api/v1/operations/shifts/$shift/close", ['actual_cash' => '1050.00', 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('cash_difference', '0.00');
        $this->pay($customer, ['amount' => '10.00', 'shift' => $shift])->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->travelBack();
    }

    public function test_opening_balance_only_once_and_adjustments_never_go_negative(): void
    {
        $customer = $this->customer();
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '0.00', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '-5.00', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $res = $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '500.00', 'note' => null])->assertCreated()
            ->assertJsonPath('entry.type', 'opening_balance')->assertJsonPath('entry.amount', '500.00')->assertJsonPath('entry.balance_after', '500.00')->assertJsonPath('entry.actor', 'Owner Person')->assertJsonPath('balance', '500.00');
        $this->assertNull($res->json('entry.sale'));
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '100.00', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->adjust($customer, ['amount' => '-600.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->adjust($customer, ['amount' => '0.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->adjust($customer, ['amount' => '-10.00', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->adjust($customer, ['amount' => '-10.00', 'note' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->adjust($customer, ['amount' => '-500.00', 'note' => 'Settled in goods'])->assertCreated()->assertJsonPath('balance', '0.00')->assertJsonPath('entry.note', 'Settled in goods');
        $this->adjust($customer, ['amount' => '25.50', 'note' => 'Delivery charge added'])->assertCreated()->assertJsonPath('balance', '25.50');
        $this->assertSame('25.50', $this->balance($customer));
        $this->assertDatabaseCount('customer_ledger', 3);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'customer.ledger_opening_balance')->count());
        // A customer who already has khata activity cannot get an opening balance.
        $p = $this->product();
        $shift = $this->shift();
        $other = $this->customer('500.00', 'Second customer');
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $other]))->assertCreated();
        $this->adjust($other, ['type' => 'opening_balance', 'amount' => '100.00', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_statement_reports_opening_closing_and_running_balances_for_the_range(): void
    {
        $p = $this->product();
        $customer = $this->customer('5000.00');
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00', 'UTC'));
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '500.00', 'note' => 'Carried from paper khata'])->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $shift = $this->shift();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated()->json();
        $this->travelTo(Carbon::parse('2026-11-20 09:00:00', 'UTC'));
        $this->pay($customer, ['amount' => '100.00', 'shift' => $shift])->assertCreated();
        $url = "/api/v1/operations/customers/$customer/ledger";
        $window = $this->getJson("$url?from=2026-11-05&to=2026-11-15")->assertOk()
            ->assertJsonPath('from', '2026-11-05')->assertJsonPath('to', '2026-11-15')
            ->assertJsonPath('opening_balance', '500.00')->assertJsonPath('closing_balance', '700.00')
            ->assertJsonPath('customer.balance', '600.00')->assertJsonPath('customer.credit_limit', '5000.00')->assertJsonPath('customer.available', '4400.00')
            ->assertJsonPath('entries.0.type', 'credit_sale')->assertJsonPath('entries.0.amount', '200.00')->assertJsonPath('entries.0.balance_after', '700.00')
            ->assertJsonPath('entries.0.sale.public_id', $sale['public_id'])->assertJsonPath('entries.0.sale.receipt_number', $sale['receipt_number'])
            ->assertJsonPath('entries.0.branch', DB::table('branches')->where('id', $this->branchId)->value('name'))->assertJsonPath('entries.0.actor', 'Owner Person')
            ->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 1);
        $this->assertCount(1, $window->json('entries'));
        $all = $this->getJson("$url?from=2026-10-01&to=2026-11-25")->assertOk()->assertJsonPath('opening_balance', '0.00')->assertJsonPath('closing_balance', '600.00');
        $this->assertSame(['500.00', '700.00', '600.00'], array_column($all->json('entries'), 'balance_after'));
        $this->assertSame(['opening_balance', 'credit_sale', 'payment'], array_column($all->json('entries'), 'type'));
        $this->assertSame('-100.00', $all->json('entries.2.amount'));
        $this->assertSame('cash', $all->json('entries.2.method'));
        // Default range is the last 30 business days ending today (2026-11-20).
        $this->getJson($url)->assertOk()->assertJsonPath('from', '2026-10-22')->assertJsonPath('to', '2026-11-20')->assertJsonPath('closing_balance', '600.00');
        $this->getJson("$url?from=2026-11-15&to=2026-11-01")->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("$url?from=2026-13-01")->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->travelBack();
    }

    public function test_return_of_credit_sale_reduces_khata_first_then_cash(): void
    {
        $this->enableReturns();
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'cash', 'amount' => '50.00'], ['method' => 'credit', 'amount' => '150.00']], ['customer' => $customer]))->assertCreated()->json('public_id');
        $this->assertSame('1050.00', $this->expected($shift));
        $return = fn () => $this->postJson("/api/v1/operations/sales/$sale/returns", ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Damaged bag', 'lines' => [['product' => $p, 'quantity' => '1.000', 'restock' => true]]]);
        $first = $return()->assertCreated()->assertJsonPath('total', '100.00')->assertJsonPath('credit_amount', '100.00')->assertJsonPath('cash_amount', '0.00')->json('public_id');
        $this->assertSame('50.00', $this->balance($customer));
        $this->assertSame('1050.00', $this->expected($shift));
        $return()->assertCreated()->assertJsonPath('credit_amount', '50.00')->assertJsonPath('cash_amount', '50.00');
        $this->assertSame('0.00', $this->balance($customer));
        // Only the cash part of returns leaves the drawer.
        $this->assertSame('1000.00', $this->expected($shift));
        $entries = DB::table('customer_ledger')->where('type', 'credit_return')->orderBy('id')->get();
        $this->assertSame(['-100.00', '-50.00'], $entries->map(fn ($e) => bcadd((string) $e->amount, '0', 2))->all());
        $this->assertSame(DB::table('sale_returns')->where('public_id', $first)->value('id'), $entries[0]->return_id);
        $this->getJson("/api/v1/operations/customers/$customer/ledger")->assertOk()->assertJsonPath('entries.1.type', 'credit_return')->assertJsonPath('entries.1.return.public_id', $first);
        $this->getJson("/api/v1/operations/returns/$first")->assertOk()->assertJsonPath('credit_amount', '100.00');
        $this->getJson('/api/v1/operations/reports/summary?branch='.$this->branch)->assertOk()->assertJsonPath('cash_refunds', '200.00')->assertJsonPath('credit_returns', '150.00')
            ->assertJsonPath('payments_net.cash', '0.00')->assertJsonPath('payments_net.credit', '0.00')->assertJsonPath('net_receipts', '0.00');
        $this->postJson("/api/v1/operations/shifts/$shift/close", ['actual_cash' => '1000.00', 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('cash_difference', '0.00');
    }

    public function test_return_after_khata_was_paid_refunds_cash_instead_of_a_negative_balance(): void
    {
        $this->enableReturns();
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer();
        $sale = $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated()->json('public_id');
        $this->pay($customer, ['amount' => '150.00', 'shift' => $shift])->assertCreated();
        $this->postJson("/api/v1/operations/sales/$sale/returns", ['idempotency_key' => (string) Str::uuid(), 'shift' => $shift, 'reason' => 'Wrong size', 'lines' => [['product' => $p, 'quantity' => '1.000', 'restock' => true]]])->assertCreated()
            ->assertJsonPath('credit_amount', '50.00')->assertJsonPath('cash_amount', '50.00');
        $this->assertSame('0.00', $this->balance($customer));
        $this->assertSame('1100.00', $this->expected($shift));
    }

    public function test_idempotent_retries_return_the_original_and_conflicting_reuse_is_409(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer();
        $salePayload = $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]);
        $first = $this->postJson('/api/v1/operations/sales', $salePayload)->assertCreated()->json();
        $this->postJson('/api/v1/operations/sales', $salePayload)->assertCreated()->assertJsonPath('public_id', $first['public_id'])->assertJsonPath('customer.balance_after', '200.00');
        $this->assertDatabaseCount('customer_ledger', 1);
        $body = ['amount' => '40.00', 'shift' => $shift, 'idempotency_key' => (string) Str::uuid()];
        $original = $this->pay($customer, $body)->assertCreated()->json();
        $this->assertSame($original, $this->pay($customer, $body)->assertCreated()->json());
        $this->assertSame('160.00', $this->balance($customer));
        $this->pay($customer, ['amount' => '41.00'] + $body)->assertConflict();
        $adjust = ['amount' => '-10.00', 'note' => 'Rounding waiver', 'idempotency_key' => (string) Str::uuid()];
        $entry = $this->adjust($customer, $adjust)->assertCreated()->json();
        $this->assertSame($entry, $this->adjust($customer, $adjust)->assertCreated()->json());
        $this->adjust($customer, ['note' => 'Different reason'] + $adjust)->assertConflict();
        // A key bound to a payment cannot be replayed as an adjustment, or by someone else.
        $this->adjust($customer, ['idempotency_key' => $body['idempotency_key']])->assertConflict();
        $this->assertDatabaseCount('customer_ledger', 3);
        $this->assertSame('150.00', $this->balance($customer));
        $this->assertSame('1040.00', $this->expected($shift));
    }

    public function test_permissions_for_credit_sales_collections_statements_and_limits(): void
    {
        $p = $this->product();
        $customer = $this->customer();
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '100.00', 'note' => null])->assertCreated();
        // No credit_sell: cash sales fine, credit refused.
        $plain = $this->staff(['sales.create', 'sales.view']);
        $this->actAs($plain);
        $shift = $this->staffShift();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertForbidden();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'cash', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated();
        // No collect: cannot receive payments or read statements.
        $this->pay($customer, ['shift' => $shift])->assertForbidden();
        $this->getJson("/api/v1/operations/customers/$customer/ledger")->assertForbidden();
        $this->adjust($customer, [])->assertForbidden();
        $this->postJson("/api/v1/operations/shifts/$shift/close", ['actual_cash' => '200.00', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        // Template cashier: may sell on credit and collect, but not manage credit.
        $cashier = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $cashier->id]);
        DB::table('user_role_assignments')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $cashier->id, 'role_id' => DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->value('id'), 'branch_id' => $this->branchId]);
        $this->actAs($cashier);
        $shift = $this->staffShift();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertCreated();
        $this->pay($customer, ['shift' => $shift, 'amount' => '30.00'])->assertCreated()->assertJsonPath('payment.balance_after', '270.00');
        $this->getJson("/api/v1/operations/customers/$customer/ledger")->assertOk()->assertJsonPath('customer.balance', '270.00');
        $this->adjust($customer, [])->assertForbidden();
        // Another cashier's shift cannot take the cash.
        $this->actAs($this->owner);
        $ownerShift = $this->shift();
        $this->actAs($cashier);
        $this->pay($customer, ['shift' => $ownerShift])->assertForbidden();
        // customers.manage without credit_manage: may edit details but not the limit.
        $editor = $this->staff(['customers.manage', 'sales.create']);
        $this->actAs($editor);
        $base = ['branch' => $this->branch, 'name' => 'Renamed Traders', 'price_tier' => 'retail', 'marketing_consent' => false];
        $this->putJson("/api/v1/operations/customers/$customer", $base + ['credit_limit' => '5000.00'])->assertForbidden();
        $this->putJson("/api/v1/operations/customers/$customer", $base + ['credit_limit' => '1000.00'])->assertOk()->assertJsonPath('credit_limit', '1000.00');
        $this->putJson("/api/v1/operations/customers/$customer", $base)->assertOk()->assertJsonPath('name', 'Renamed Traders')->assertJsonPath('credit_limit', '1000.00');
        $this->postJson('/api/v1/operations/customers', $base + ['name' => 'New one', 'credit_limit' => '100.00'])->assertForbidden();
        $this->actAs($this->owner);
        $this->putJson("/api/v1/operations/customers/$customer", $base + ['credit_limit' => '2500.00'])->assertOk()->assertJsonPath('credit_limit', '2500.00')->assertJsonPath('balance', '270.00');
        $this->putJson("/api/v1/operations/customers/$customer", $base + ['credit_limit' => '-1.00'])->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $audit = DB::table('audit_logs')->where('action', 'customer.credit_limit_changed')->orderByDesc('id')->first();
        $this->assertSame(['from' => '1000.00', 'to' => '2500.00'], json_decode($audit->metadata, true));
    }

    public function test_limit_only_update_keeps_marketing_consent(): void
    {
        $id = $this->postJson('/api/v1/operations/customers', ['branch' => $this->branch, 'name' => 'Consenting', 'price_tier' => 'retail', 'marketing_consent' => true])->assertCreated()->json('public_id');
        // The khata screen sends only the fields it has; consent must not be required or reset.
        $this->putJson("/api/v1/operations/customers/$id", ['branch' => $this->branch, 'name' => 'Consenting', 'price_tier' => 'retail', 'credit_limit' => '2500.00'])->assertOk();
        $row = DB::table('customers')->where('public_id', $id)->first();
        $this->assertTrue((bool) $row->marketing_consent);
        $this->assertSame('2500.00', bcadd((string) $row->credit_limit, '0', 2));
        $this->postJson('/api/v1/operations/customers', ['branch' => $this->branch, 'name' => 'No consent field', 'price_tier' => 'retail'])->assertUnprocessable()->assertJsonValidationErrors('marketing_consent');
    }

    public function test_disabled_feature_blocks_credit_writes(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $customer = $this->customer();
        DB::table('tenant_feature_overrides')->insert(['tenant_id' => $this->tenant->id, 'feature_id' => DB::table('features')->where('key', 'customer_credit')->value('id'), 'enabled' => false]);
        $this->getJson('/api/v1/operations/bootstrap')->assertOk()->assertJsonPath('modules.customer_credit', false);
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $customer]))->assertForbidden();
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '100.00', 'note' => null])->assertForbidden();
        $this->pay($customer, ['shift' => $shift])->assertForbidden();
        $this->assertDatabaseCount('customer_ledger', 0);
    }

    public function test_other_tenants_customers_are_not_reachable(): void
    {
        $p = $this->product();
        $shift = $this->shift();
        $otherOwner = User::factory()->create();
        $other = app(TenantService::class)->create($otherOwner, 'Other shop');
        $foreign = (string) Str::uuid();
        DB::table('customers')->insert(['tenant_id' => $other->id, 'public_id' => $foreign, 'name' => 'Foreign', 'price_tier' => 'retail', 'credit_limit' => '1000.00', 'balance' => '300.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/v1/operations/customers/$foreign/ledger")->assertNotFound();
        $this->pay($foreign, ['shift' => $shift])->assertNotFound();
        $this->adjust($foreign, [])->assertNotFound();
        $this->postJson('/api/v1/operations/sales', $this->sale($p, $shift, [['method' => 'credit', 'amount' => '200.00']], ['customer' => $foreign]))->assertNotFound();
        $this->putJson("/api/v1/operations/customers/$foreign", ['branch' => $this->branch, 'name' => 'Hijack', 'price_tier' => 'retail', 'marketing_consent' => false, 'credit_limit' => '9999.00'])->assertNotFound();
        // A foreign branch cannot be used to collect for one of our customers.
        $mine = $this->customer();
        $foreignBranch = DB::table('branches')->where('tenant_id', $other->id)->value('public_id');
        $this->pay($mine, ['branch' => $foreignBranch])->assertNotFound();
        $this->assertDatabaseCount('customer_ledger', 0);
        $this->assertSame('300.00', bcadd((string) DB::table('customers')->where('public_id', $foreign)->value('balance'), '0', 2));
    }

    public function test_ledger_rows_cannot_be_updated(): void
    {
        $customer = $this->customer();
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '100.00', 'note' => null])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('customer_ledger')->update(['amount' => '1.00']);
    }

    public function test_ledger_rows_cannot_be_deleted(): void
    {
        $customer = $this->customer();
        $this->adjust($customer, ['type' => 'opening_balance', 'amount' => '100.00', 'note' => null])->assertCreated();
        try {
            DB::table('customer_ledger')->delete();
            $this->fail('Ledger delete should be rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertDatabaseCount('customer_ledger', 1);
    }
}
