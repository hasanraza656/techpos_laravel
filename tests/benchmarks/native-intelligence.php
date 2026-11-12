<?php

use App\Domain\Intelligence\InventoryIntelligence;
use App\Domain\Operations\RegisterService;
use App\Domain\Operations\SaleService;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\TenantService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_starts_with(config('database.connections.mysql.database'), 'tech_pos_codex_test_')) {
    fwrite(STDERR, "Dedicated MySQL-compatible test database required.\n");
    exit(1);
}
if (in_array('--reuse', $argv, true)) {
    $tenant = Tenant::where('name', 'Isolated native benchmark')->latest('id')->firstOrFail();
    $owner = User::findOrFail(DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('is_owner', true)->value('user_id'));
    $c = app(TenantContext::class);
    $c->tenant = $tenant;
    $c->user = $owner;
    $c->owner = true;
    auth()->setUser($owner);
    $branch = DB::table('branches')->where('tenant_id', $tenant->id)->first();
    $warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->first();
    $size = DB::table('products')->where('tenant_id', $tenant->id)->count();
    $measurements = [];
    foreach ([false, true] as $advanced) {
        $samples = [];
        for ($trial = 0; $trial < 3; $trial++) {
            $start = hrtime(true);
            $result = app(InventoryIntelligence::class)->inventory($c, ['branch' => $branch->public_id, 'warehouse' => $warehouse->public_id, 'advanced' => $advanced, 'per_page' => 25]);
            $samples[] = (hrtime(true) - $start) / 1e6;
            if (count($result['data']) !== 25) {
                throw new RuntimeException('Expected 25 scoped rows.');
            }
        }sort($samples);
        $measurements[] = ['products' => $size, 'mode' => $advanced ? 'statistical_forecast' : 'low_stock', 'samples_ms' => $samples, 'median_ms' => $samples[1], 'max_ms' => max($samples)];
    }
    $result = ['recorded_at' => now()->toIso8601String(), 'conditions' => 'Same isolated populated fixture after catalog count/index optimization. Three CLI/domain samples; no HTTP/browser/large history.', 'measurements' => $measurements];
    file_put_contents(dirname(base_path()).'/.tools/native-intelligence-optimized.json', json_encode($result, JSON_PRETTY_PRINT));
    echo json_encode($result, JSON_PRETTY_PRINT)."\n";
    exit;
}
$app->make(DatabaseSeeder::class)->run();
$owner = User::factory()->create();
$tenant = app(TenantService::class)->create($owner, 'Isolated native benchmark');
$c = app(TenantContext::class);
$c->tenant = $tenant;
$c->user = $owner;
$c->owner = true;
auth()->setUser($owner);
DB::table('subscriptions')->where('tenant_id', $tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'premium')->value('id')]);
foreach (['reports', 'analytics', 'forecasting'] as $feature) {
    app(ModuleService::class)->set($tenant->id, $feature, true);
}
$branch = DB::table('branches')->where('tenant_id', $tenant->id)->first();
$warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->first();
$register = DB::table('registers')->where('tenant_id', $tenant->id)->first();
$end = CarbonImmutable::now('Asia/Karachi')->startOfDay();
$created = $end->subDays(28)->utc()->toDateTimeString();
$built = 0;
$measurements = [];
foreach ([100, 10000, 100000] as $size) {
    while ($built < $size) {
        $batch = [];
        for ($i = $built; $i < min($size, $built + 500); $i++) {
            $batch[] = ['tenant_id' => $tenant->id, 'public_id' => (string) Str::uuid(), 'name' => 'QA product '.$i, 'sku' => 'NATIVE-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '6.0000', 'selling_price' => '10.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'reorder_level' => $i < 25 ? '12.000' : '3.000', 'track_stock' => true, 'active' => true, 'version' => 1, 'created_at' => $created, 'updated_at' => $created];
        }
        DB::transaction(function () use ($batch, $tenant, $branch, $warehouse, $owner, $created) {
            DB::table('products')->insert($batch);
            $products = DB::table('products')->where('tenant_id', $tenant->id)->whereIn('public_id', array_column($batch, 'public_id'))->get(['id']);
            $balances = [];
            $movements = [];
            foreach ($products as $p) {
                $balances[] = ['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p->id, 'quantity' => '10.000', 'reserved_quantity' => '0.000', 'cost_value' => '60.0000', 'created_at' => $created, 'updated_at' => $created];
                $movements[] = ['public_id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p->id, 'user_id' => $owner->id, 'type' => 'opening_stock', 'quantity' => '10.000', 'reserved_delta' => '0.000', 'cost_value' => '60.0000', 'source_type' => 'benchmark_opening', 'source_id' => (string) Str::uuid(), 'reason' => 'Isolated QA counted stock', 'created_at' => $created];
            }DB::table('stock_balances')->insert($balances);
            DB::table('inventory_movements')->insert($movements);
        });
        $built += count($batch);
    }
    if ($size === 100) {
        Carbon::setTestNow($end->subDay()->addHours(9));
        $shift = app(RegisterService::class)->open($c, $branch, ['register' => $register->public_id, 'opening_float' => '0.00', 'idempotency_key' => (string) Str::uuid()]);
        $lines = DB::table('products')->where('tenant_id', $tenant->id)->orderBy('sku')->get(['public_id'])->map(fn ($p) => ['product' => $p->public_id, 'quantity' => '1.000'])->all();
        $sale = app(SaleService::class)->finalize($c, ['idempotency_key' => (string) Str::uuid(), 'branch' => $branch->public_id, 'warehouse' => $warehouse->public_id, 'shift' => $shift->public_id, 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount' => '1000.00']]]);
        if ($sale['total'] !== '1000.00' || $sale['cost_total'] !== '600.0000') {
            throw new RuntimeException('Source sale failed reconciliation.');
        }app(RegisterService::class)->close($c, $shift->public_id, ['actual_cash' => '1000.00', 'idempotency_key' => (string) Str::uuid()]);
        Carbon::setTestNow();
    }
    foreach ([false, true] as $advanced) {
        $samples = [];
        $queryCounts = [];
        for ($trial = 0; $trial < 3; $trial++) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $result = app(InventoryIntelligence::class)->inventory($c, ['branch' => $branch->public_id, 'warehouse' => $warehouse->public_id, 'advanced' => $advanced, 'per_page' => 25]);
            $samples[] = (hrtime(true) - $start) / 1e6;
            $queryCounts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            if (count($result['data']) !== 25) {
                throw new RuntimeException('Expected 25 scoped rows.');
            }
        }sort($samples);
        $measurements[] = ['products' => $size, 'mode' => $advanced ? 'statistical_forecast' : 'low_stock', 'page_size' => 25, 'samples_ms' => $samples, 'median_ms' => $samples[1], 'max_ms' => max($samples), 'query_counts' => $queryCounts];
    }
}
$result = ['recorded_at' => now()->toIso8601String(), 'database' => DB::selectOne('select version() as v')->v, 'php' => PHP_VERSION, 'conditions' => 'Local CLI/domain service, isolated test tenant, 100/10k/100k active products and aligned opening ledger/balances. One actual 100-line sale in the completed window. Three samples per case; excludes seeding, HTTP, browser, cross-tenant concurrency and large sales history.', 'measurements' => $measurements, 'peak_process_memory_bytes' => memory_get_peak_usage(true)];
file_put_contents(dirname(base_path()).'/.tools/native-intelligence-benchmark.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT)."\n";
