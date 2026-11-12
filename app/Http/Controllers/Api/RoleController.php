<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Services\InvitationService;
use App\Services\RoleTemplates;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(TenantContext $c)
    {
        $c->authorize('roles.manage');

        return ['permissions' => DB::table('permissions')->get(), 'templates' => RoleTemplates::all(), 'roles' => DB::table('roles')->where('tenant_id', $c->tenant->id)->get()->map(function ($r) {
            $r->permissions = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_id', $r->id)->pluck('key');
            unset($r->id,$r->tenant_id);

            return $r;
        })];
    }

    public function save(Request $r, TenantContext $c, ?string $role = null)
    {
        $c->authorize('roles.manage');
        $existing = $role ? DB::table('roles')->where('tenant_id', $c->tenant->id)->where('public_id', $role)->first() : null;
        if ($role) {
            abort_unless($existing, 404);
        }
        $d = $r->validate(['name' => ['required', 'string', 'max:80', Rule::unique('roles')->where('tenant_id', $c->tenant->id)->ignore($existing?->id)], 'permissions' => 'required|array|max:200', 'permissions.*' => 'required|string|distinct|exists:permissions,key']);
        foreach ($d['permissions'] as $key) {
            abort_unless($c->allows($key), 403, 'You cannot grant a permission you do not have.');
        }
        if ($existing) {
            foreach (DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_id', $existing->id)->pluck('key') as $key) {
                abort_unless($c->allows($key), 403, 'You cannot alter a more privileged role.');
            }
        }

        return DB::transaction(function () use ($existing, $c, $d) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $id = $existing?->id;
            if ($id && in_array('sales.create', $d['permissions'])) {
                $cashierRoles = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('permissions.key', 'sales.create')->where('role_id', '!=', $id)->pluck('role_id');
                $existingCashiers = DB::table('user_role_assignments')->where('tenant_id', $c->tenant->id)->whereIn('role_id', $cashierRoles)->pluck('user_id');
                $roleMembers = DB::table('user_role_assignments')->where('tenant_id', $c->tenant->id)->where('role_id', $id)->pluck('user_id');
                $pendingCashiers = DB::table('tenant_invitations')->where('tenant_id', $c->tenant->id)->where(function ($q) use ($cashierRoles, $id) {
                    $q->whereIn('role_id', $cashierRoles)->orWhere('role_id', $id);
                })->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
                app(Entitlements::class)->assertLimit($c->tenant->id, 'cashiers', $existingCashiers->merge($roleMembers)->unique()->count() + $pendingCashiers, 0);
            }
            $uuid = $existing?->public_id ?? (string) Str::uuid();
            if ($id) {
                DB::table('roles')->where('id', $id)->update(['name' => $d['name'], 'version'=>DB::raw('version + 1'), 'updated_at' => now()]);
            } else {
                $id = DB::table('roles')->insertGetId(['tenant_id' => $c->tenant->id, 'public_id' => $uuid, 'name' => $d['name'], 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('role_permissions')->where('role_id', $id)->delete();
            foreach (DB::table('permissions')->whereIn('key', $d['permissions'])->pluck('id') as $p) {
                DB::table('role_permissions')->insert(['role_id' => $id, 'permission_id' => $p]);
            }
            Audit::record('role.saved', $c->tenant->id, $uuid, ['permissions' => $d['permissions']]);

            return ['public_id' => $uuid, 'message' => 'Role saved.'];
        });
    }

    public function members(TenantContext $c)
    {
        $c->authorize('employees.view');

        return DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')->where('tenant_id', $c->tenant->id)->select('users.id', 'users.public_id', 'users.name', 'users.email', 'tenant_users.is_owner')->get()->map(function ($u) use ($c) {
            $u->assignments = DB::table('user_role_assignments as a')->join('roles', 'roles.id', '=', 'a.role_id')->leftJoin('branches', 'branches.id', '=', 'a.branch_id')->where('a.tenant_id', $c->tenant->id)->where('a.user_id', $u->id)->select('roles.public_id as role', 'roles.name as role_name', 'branches.public_id as branch', 'branches.name as branch_name')->get();
            unset($u->id);

            return $u;
        });
    }

    public function assign(Request $r, TenantContext $c)
    {
        $c->authorize('employees.manage');
        $c->authorize('roles.manage');
        $d = $r->validate(['user' => 'required|uuid', 'role' => 'required|uuid', 'branch' => 'nullable|uuid']);
        $u = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')->where('tenant_id', $c->tenant->id)->where('users.public_id', $d['user'])->select('users.id', 'tenant_users.is_owner')->first();
        abort_unless($u, 404);
        abort_if($u->is_owner, 422, 'Owner permissions cannot be replaced by role assignment.');
        $role = DB::table('roles')->where('tenant_id', $c->tenant->id)->where('public_id', $d['role'])->first();
        abort_unless($role, 404);
        foreach (DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_id', $role->id)->pluck('key') as $key) {
            abort_unless($c->allows($key), 403);
        }
        $branch = null;
        if (! empty($d['branch'])) {
            $branch = DB::table('branches')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->where('public_id', $d['branch'])->value('id');
            abort_unless($branch, 404);
        }
        DB::transaction(function () use ($c, $u, $role, $branch) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            DB::table('tenant_users')->where('tenant_id', $c->tenant->id)->where('user_id', $u->id)->lockForUpdate()->first();
            app(InvitationService::class)->assertCashierLimit($c->tenant->id, $role->id, $u->id, true);
            DB::table('user_role_assignments')->where('tenant_id', $c->tenant->id)->where('user_id', $u->id)->delete();
            DB::table('user_role_assignments')->insert(['tenant_id' => $c->tenant->id, 'user_id' => $u->id, 'role_id' => $role->id, 'branch_id' => $branch]);
            Audit::record('role.assigned', $c->tenant->id, (string) $u->id, ['role' => $role->public_id, 'branch' => $branch]);
        });

        return ['message' => 'Role assigned.'];
    }
}
