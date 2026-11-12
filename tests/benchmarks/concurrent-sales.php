<?php

// Standalone, destructive fixtures ONLY in the dedicated test database. Never run against tech_pos.
use App\Domain\Operations\RegisterService;
use App\Domain\Operations\ReturnService;
use App\Domain\Operations\SaleService;
use App\Domain\Operations\StockLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_starts_with(config('database.connections.mysql.database'), 'tech_pos_codex_test_')) {
    fwrite(STDERR, "Dedicated MySQL-compatible test database required.\n");
    exit(1);
}
function contextFor(string $tenant, string $user): TenantContext
{
    $c = app(TenantContext::class);
    $c->tenant = Tenant::where('public_id', $tenant)->firstOrFail();
    $c->user = User::where('public_id', $user)->firstOrFail();
    $membership = DB::table('tenant_users')->where('tenant_id', $c->tenant->id)->where('user_id', $c->user->id)->first();
    abort_unless($membership, 403);
    $c->owner = (bool) $membership->is_owner;

    return $c;
}
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $c = contextFor($input['tenant'], $input['user']);
    while (microtime(true) < $input['start']) {
        usleep(1000);
    }
    $begin = microtime(true);
    try {
        $sale = isset($input['return_sale']) ? app(ReturnService::class)->finalize($c, $input['return_sale'], $input['payload']) : app(SaleService::class)->finalize($c, $input['payload']);
        $result = ['status' => 'accepted', 'id' => $sale['public_id']];
    } catch (ValidationException $e) {
        $result = ['status' => 'rejected', 'code' => 422, 'errors' => $e->errors()];
    }
    $result['elapsed_ms'] = round((microtime(true) - $begin) * 1000, 3);
    echo json_encode($result);
    exit;
}
$app->make(DatabaseSeeder::class)->run();
$owner = User::factory()->create();
$tenant = app(TenantService::class)->create($owner, 'Isolated concurrency benchmark');
$c = contextFor($tenant->public_id, $owner->public_id);
$branch = DB::table('branches')->where('tenant_id', $tenant->id)->first();
$warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->first();
$register = DB::table('registers')->where('tenant_id', $tenant->id)->first();
$pid = DB::table('products')->insertGetId(['public_id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'QA race item', 'sku' => 'RACE', 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '12.0000', 'selling_price' => '25.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'reorder_level' => '0.000', 'track_stock' => true, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
$product = DB::table('products')->find($pid);
DB::transaction(fn () => app(StockLedger::class)->move($tenant->id, $branch->id, $warehouse->id, $product, '5.000', 'opening_stock', 'benchmark', (string) Str::uuid(), $owner->id, 'Test-only counted stock', '12.0000'));
$fixtures = [];
$cashierRole = DB::table('roles')->where('tenant_id', $tenant->id)->where('name', 'Cashier')->value('id');
for ($i = 0; $i < 8; $i++) {
    if ($i === 0) {
        $user = $owner;
        $r = $register;
    } else {
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'is_owner' => false]);
        DB::table('user_role_assignments')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $cashierRole, 'branch_id' => $branch->id]);
        $rid = DB::table('registers')->insertGetId(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'public_id' => (string) Str::uuid(), 'name' => 'QA Register '.$i, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $r = DB::table('registers')->find($rid);
    }
    $ctx = contextFor($tenant->public_id, $user->public_id);
    $shift = app(RegisterService::class)->open($ctx, $branch, ['register' => $r->public_id, 'opening_float' => '0.00', 'idempotency_key' => (string) Str::uuid()]);
    $fixtures[] = ['tenant' => $tenant->public_id, 'user' => $user->public_id, 'payload' => ['idempotency_key' => (string) Str::uuid(), 'branch' => $branch->public_id, 'warehouse' => $warehouse->public_id, 'shift' => $shift->public_id, 'lines' => [['product' => $product->public_id, 'quantity' => '1.000']], 'payments' => [['method' => 'cash', 'amount' => '25.00']]]];
}
function race(array $fixtures): array
{
    $start = microtime(true) + 3;
    $workers = [];
    foreach ($fixtures as $fixture) {
        $fixture['start'] = $start;
        $process = new Process([PHP_BINARY, __FILE__, 'worker'], base_path(), null, json_encode($fixture), 30);
        $process->start();
        $workers[] = $process;
    }
    $results = [];
    foreach ($workers as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput().$process->getOutput());
        }$results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    return $results;
}
$duplicates = race(array_fill(0, 8, $fixtures[0]));
if (DB::table('sales')->where('tenant_id', $tenant->id)->count() !== 1) {
    throw new RuntimeException('Duplicate race created multiple sales.');
}
// First cashier has already sold once; eight separate cashier/shift requests now compete for four units.
foreach ($fixtures as &$f) {
    $f['payload']['idempotency_key'] = (string) Str::uuid();
}unset($f);
$competing = race($fixtures);
$accepted = count(array_filter($competing, fn ($r) => $r['status'] === 'accepted'));
$rejected = count(array_filter($competing, fn ($r) => $r['status'] === 'rejected'));
$balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->first();
if ($accepted !== 4 || $rejected !== 4 || bccomp($balance->quantity, '0', 3) !== 0 || bccomp($balance->cost_value, '0', 4) !== 0 || DB::table('sales')->where('tenant_id', $tenant->id)->count() !== 5 || DB::table('inventory_movements')->where('tenant_id', $tenant->id)->count() !== 6) {
    throw new RuntimeException('Stock race failed reconciliation.');
}
DB::table('subscriptions')->where('tenant_id', $tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'standard')->value('id')]);
DB::table('tenant_feature_overrides')->insert(['tenant_id' => $tenant->id, 'feature_id' => DB::table('features')->where('key', 'returns')->value('id'), 'enabled' => true]);
DB::table('role_permissions')->insertOrIgnore(['role_id' => $cashierRole, 'permission_id' => DB::table('permissions')->where('key', 'sales.refund')->value('id')]);
$returnFixtures = [];
foreach ($fixtures as $fixture) {
    $fixture['return_sale'] = $duplicates[0]['id'];
    $fixture['payload'] = ['idempotency_key' => (string) Str::uuid(), 'shift' => $fixture['payload']['shift'], 'reason' => 'Parallel test-only return', 'lines' => [['product' => $product->public_id, 'quantity' => '1.000', 'restock' => true]]];
    $returnFixtures[] = $fixture;
}
$refunds = race($returnFixtures);
$balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->first();
if (count(array_filter($refunds, fn ($r) => $r['status'] === 'accepted')) !== 1 || DB::table('sale_returns')->where('tenant_id', $tenant->id)->count() !== 1 || bccomp($balance->quantity, '1.000', 3) !== 0 || bccomp($balance->cost_value, '12.0000', 4) !== 0) {
    throw new RuntimeException('Concurrent returns exceeded the source quantity or cost.');
}
$result = ['recorded_at' => now()->toIso8601String(), 'php' => PHP_VERSION, 'database' => DB::selectOne('select version() as version')->version, 'conditions' => 'Local XAMPP, eight PHP CLI processes; same-branch serialization; test-only fixtures. Timings exclude PHP bootstrap/barrier.', 'duplicate_race' => $duplicates, 'competing_cashiers' => $competing, 'sales' => 5, 'remaining_quantity' => bcadd($balance->quantity, '0', 3), 'remaining_value' => bcadd($balance->cost_value, '0', 4), 'accepted_competing' => 4, 'rejected_competing' => 4];
$result['return_race'] = $refunds;
$result['after_return_quantity'] = bcadd($balance->quantity, '0', 3);
$result['after_return_value'] = bcadd($balance->cost_value, '0', 4);
$target = dirname(base_path()).'/.tools/concurrent-sales.json';
file_put_contents($target, json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT)."\n";
