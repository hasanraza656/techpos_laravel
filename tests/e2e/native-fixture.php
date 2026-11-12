<?php

use App\Domain\Operations\RegisterService;
use App\Domain\Operations\SaleService;
use App\Domain\Operations\StockLedger;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\TenantService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Invoked only through the guarded E2E helper; never seeds the business database.
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'e2e.sqlite') {
    throw new RuntimeException('Isolated E2E database required.');
}
$end = CarbonImmutable::now('Asia/Karachi')->startOfDay();
Carbon::setTestNow($end->subDays(28)->addHours(9));
$owner = User::create(['name' => 'Native Analyst', 'email' => 'native-e2e@example.test', 'password' => 'NativePassword123']);
$owner->forceFill(['email_verified_at' => now()])->save();
auth()->setUser($owner);
$tenant = app(TenantService::class)->create($owner, 'Native Analytics QA');
$c = app(TenantContext::class);
$c->tenant = $tenant;
$c->user = $owner;
$c->owner = true;
DB::table('subscriptions')->where('tenant_id', $tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'premium')->value('id')]);
foreach (['reports', 'analytics', 'forecasting'] as $key) {
    app(ModuleService::class)->set($tenant->id, $key, true);
}
$branch = DB::table('branches')->where('tenant_id', $tenant->id)->first();
$warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->first();
$register = DB::table('registers')->where('tenant_id', $tenant->id)->first();
$id = DB::table('products')->insertGetId(['tenant_id' => $tenant->id, 'public_id' => (string) Str::uuid(), 'name' => 'Forecast Tea', 'sku' => 'FORECAST-TEA', 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '6.0000', 'selling_price' => '10.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'track_stock' => true, 'active' => true, 'reorder_level' => '3.000', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
$product = DB::table('products')->find($id);
DB::transaction(fn () => app(StockLedger::class)->move($tenant->id, $branch->id, $warehouse->id, $product, '30.000', 'opening_stock', 'e2e_fixture', (string) Str::uuid(), $owner->id, 'QA fixture counted stock', '6.0000'));
$shift = app(RegisterService::class)->open($c, $branch, ['register' => $register->public_id, 'opening_float' => '0.00', 'idempotency_key' => (string) Str::uuid()]);
for ($day = 0; $day < 28; $day++) {
    Carbon::setTestNow($end->subDays(28 - $day)->addHours(12));
    app(SaleService::class)->finalize($c, ['idempotency_key' => (string) Str::uuid(), 'branch' => $branch->public_id, 'warehouse' => $warehouse->public_id, 'shift' => $shift->public_id, 'lines' => [['product' => $product->public_id, 'quantity' => '1.000']], 'payments' => [['method' => 'cash', 'amount' => '10.00']]]);
}
app(RegisterService::class)->close($c, $shift->public_id, ['actual_cash' => '280.00', 'idempotency_key' => (string) Str::uuid()]);
Carbon::setTestNow();
echo json_encode(['from' => $end->subDays(28)->toDateString(), 'to' => $end->subDay()->toDateString()]);
