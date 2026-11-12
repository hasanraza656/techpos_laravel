<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\OperationScope;
use App\Http\Controllers\Controller;
use App\Services\AccountSecurity;
use App\Services\Entitlements;
use App\Services\TenantMedia;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function show(TenantContext $c, Entitlements $e)
    {
        return ['tenant' => $c->tenant, 'logo' => TenantMedia::present(DB::table('tenant_media')->where('tenant_id', $c->tenant->id)->where('slot', 'logo')->first()), 'permissions' => DB::table('permissions')->pluck('key')->filter(fn ($p) => $c->allows($p))->values(),
            'subscription' => $e->subscription($c->tenant->id), 'plan' => $e->plan($c->tenant->id),
            'currency_locked' => DB::table('inventory_movements')->where('tenant_id', $c->tenant->id)->exists() || DB::table('sales')->where('tenant_id', $c->tenant->id)->exists() || DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->exists(),
            'branches' => DB::table('branches')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->select('public_id', 'name', 'address')->get(),
            'onboarding' => DB::table('onboarding_progress')->where('tenant_id', $c->tenant->id)->first()];
    }

    public function branches(Request $r, TenantContext $c, Entitlements $e)
    {
        $c->authorize('branches.manage');
        $data = $r->validate(['name' => 'required|string|max:150', 'address' => 'nullable|string|max:255']);

        return DB::transaction(function () use ($data, $c, $e) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $e->assertLimit($c->tenant->id, 'branches', DB::table('branches')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->count());
            $uuid = (string) Str::uuid();
            DB::table('branches')->insert($data + ['tenant_id' => $c->tenant->id, 'public_id' => $uuid, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('branch.created', $c->tenant->id, $uuid);
            app(OperationScope::class)->defaults($c->tenant->id, DB::table('branches')->where('public_id', $uuid)->value('id'));

            return response()->json(['public_id' => $uuid] + $data, 201);
        });
    }

    public function switch(Request $r, TenantContext $c)
    {
        Audit::record('tenant.switched', $c->tenant->id);

        return ['tenant' => $c->tenant->public_id];
    }

    public function device(Request $r, TenantContext $c)
    {
        $data = $r->validate(['device_identifier' => 'required|uuid', 'name' => 'required|string|max:100', 'schema_version' => 'required|integer|in:1']);
        $identity = ['tenant_id' => $c->tenant->id, 'user_id' => $r->user()->id, 'device_identifier' => $data['device_identifier']];
        $existing = DB::table('devices')->where($identity)->first();
        DB::table('devices')->updateOrInsert($identity, ['public_id' => $existing?->public_id ?? (string) Str::uuid(), 'session_hash' => AccountSecurity::sessionHash($r->session()->getId()), 'revoked_at' => null, 'name' => $data['name'], 'schema_version' => 1, 'last_seen_at' => now(), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);

        return ['message' => 'Device registered.'];
    }
}
