<?php

use App\Domain\Operations\StockLedger;
use App\Domain\Platform\BillingInvoices;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ProofStorage;
use App\Services\TenantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! app()->environment('testing') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'e2e.sqlite') {
    throw new RuntimeException('Isolated E2E database required.');
}
$result = [];
foreach (['North', 'South'] as $index => $name) {
    $owner = User::create(['name' => $name.' Owner', 'email' => strtolower($name).'-portal@example.test', 'password' => 'PortalOwner123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    auth()->setUser($owner);
    $tenant = app(TenantService::class)->create($owner, 'Portal '.$name.' Shop');
    $branch = DB::table('branches')->where('tenant_id', $tenant->id)->first();
    $warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->first();
    $id = DB::table('products')->insertGetId(['tenant_id' => $tenant->id, 'public_id' => (string) Str::uuid(), 'name' => $name.' Tea', 'sku' => 'PORTAL-'.strtoupper($name), 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '60.0000', 'selling_price' => '100.00', 'tax_rate' => '0.0000', 'tax_inclusive' => false, 'track_stock' => true, 'active' => true, 'reorder_level' => '3.000', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $product = DB::table('products')->find($id);
    DB::transaction(fn () => app(StockLedger::class)->move($tenant->id, $branch->id, $warehouse->id, $product, (string) (10 + $index), 'opening_stock', 'e2e_fixture', (string) Str::uuid(), $owner->id, 'Counted QA opening stock', '60.0000'));
    $result[strtolower($name)] = $tenant->public_id;
    if ($index === 0) {
        $payment = SubscriptionPayment::create(['tenant_id' => $tenant->id, 'plan_id' => DB::table('plans')->where('key', 'standard')->value('id'), 'payment_method_id' => DB::table('payment_methods')->value('id'), 'submitted_by' => $owner->id, 'amount' => '5000.00', 'expires_at' => now()->addDays(7)]);
        app(PaymentService::class)->event($payment, 'draft');
        app(BillingInvoices::class)->forPayment($payment);
        app(ProofStorage::class)->store($payment, UploadedFile::fake()->image('qa-transfer.png'));
        app(PaymentService::class)->submit($payment, ['transaction_reference' => 'PORTAL-QA-ONLY-'.Str::uuid(), 'paid_on' => now()->toDateString(), 'amount' => '5000.00']);
        $result['payment'] = $payment->public_id;
    }
}
echo json_encode($result);
