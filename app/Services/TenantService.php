<?php

namespace App\Services;

use App\Domain\Operations\OperationScope;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantService
{
    public function create(User $owner, string $name): Tenant
    {
        return DB::transaction(function () use ($owner, $name) {
            // Serialize business creation for a global user, including requests racing registration.
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $tenant = Tenant::create(['name' => $name]);
            app(RoleTemplates::class)->seed($tenant->id);
            DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('branches')->insert(['tenant_id' => $tenant->id, 'public_id' => (string) Str::uuid(), 'name' => 'Main branch', 'created_at' => now(), 'updated_at' => now()]);
            app(OperationScope::class)->defaults($tenant->id, DB::table('branches')->where('tenant_id', $tenant->id)->value('id'));
            DB::table('subscriptions')->insert(['tenant_id' => $tenant->id, 'plan_id' => DB::table('plans')->where('key', 'free')->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('onboarding_progress')->insert(['tenant_id' => $tenant->id, 'step' => 1, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('tenant.created', $tenant->id, $tenant->public_id);

            return $tenant->refresh();
        });
    }
}
