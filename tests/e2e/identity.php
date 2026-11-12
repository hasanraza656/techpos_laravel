<?php

use App\Models\User;
use App\Notifications\BusinessInvitation;
use App\Services\ModuleService;
use App\Services\TwoFactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'e2e.sqlite') {
    fwrite(STDERR, 'E2E isolated database required.');
    exit(1);
}
if (($argv[1] ?? '') === 'platform-fixture') {
    require __DIR__.'/platform-fixture.php';
} elseif (($argv[1] ?? '') === 'native-fixture') {
    require __DIR__.'/native-fixture.php';
} elseif (($argv[1] ?? '') === 'admin') {
    $u = User::create(['name' => 'QA Admin', 'email' => 'e2e-admin@example.test', 'password' => 'BrowserAdmin123']);
    $u->forceFill(['email_verified_at' => now(), 'is_superadmin' => true])->save();
} elseif (($argv[1] ?? '') === 'verify') {
    User::where('email', 'e2e-owner@example.test')->update(['email_verified_at' => now()]);
} elseif (($argv[1] ?? '') === 'verify-ux') {
    User::where('email', 'e2e-ux@example.test')->update(['email_verified_at' => now()]);
} elseif (($argv[1] ?? '') === 'enable-bulk-ux') {
    $u = User::where('email', 'e2e-ux@example.test')->firstOrFail();
    $tenant = DB::table('tenant_users')->where('user_id', $u->id)->where('is_owner', true)->value('tenant_id');
    DB::table('subscriptions')->where('tenant_id', $tenant)->update(['plan_id' => DB::table('plans')->where('key', 'standard')->value('id')]);
    app(ModuleService::class)->set($tenant, 'bulk_data', true);
} elseif (($argv[1] ?? '') === 'verify-team') {
    User::where('email', 'e2e-team-owner@example.test')->update(['email_verified_at' => now()]);
} elseif (($argv[1] ?? '') === 'verify-operations') {
    User::where('email', 'e2e-operations@example.test')->update(['email_verified_at' => now()]);
} elseif (($argv[1] ?? '') === 'enable-returns') {
    $u = User::where('email', 'e2e-operations@example.test')->firstOrFail();
    $tenant = DB::table('tenant_users')->where('user_id', $u->id)->where('is_owner', true)->value('tenant_id');
    DB::table('subscriptions')->where('tenant_id', $tenant)->update(['plan_id' => DB::table('plans')->where('key', 'standard')->value('id')]);
    DB::table('tenant_feature_overrides')->updateOrInsert(['tenant_id' => $tenant, 'feature_id' => DB::table('features')->where('key', 'returns')->value('id')], ['enabled' => true]);
} elseif (($argv[1] ?? '') === 'operation-counts') {
    $u = User::where('email', 'e2e-operations@example.test')->firstOrFail();
    $tenant = DB::table('tenant_users')->where('user_id', $u->id)->where('is_owner', true)->value('tenant_id');
    $counts = [];
    foreach (['sales', 'sale_returns', 'inventory_movements', 'cash_events', 'register_shifts'] as $table) {
        $counts[$table] = DB::table($table)->where('tenant_id', $tenant)->count();
    }echo json_encode($counts);
} elseif (($argv[1] ?? '') === 'otp-team') {
    $u = User::where('email', 'e2e-team-owner@example.test')->firstOrFail();
    echo json_encode(['code' => app(TwoFactor::class)->totp()->getCurrentOtp($u->two_factor_secret)]);
} elseif (($argv[1] ?? '') === 'invitation') {
    foreach (DB::table('jobs')->orderByDesc('id')->get() as $job) {
        $payload = json_decode($job->payload, true);
        $command = unserialize($payload['data']['command']);
        if ($command instanceof SendQueuedNotifications && $command->notification instanceof BusinessInvitation) {
            echo json_encode(['token' => $command->notification->token]);
            exit(0);
        }
    }
    fwrite(STDERR, 'No invitation found.');
    exit(1);
}
