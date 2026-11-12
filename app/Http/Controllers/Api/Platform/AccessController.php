<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Platform\PlatformAccess;
use App\Domain\Platform\PlatformCommand;
use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Services\InvitationService;
use App\Services\RoleTemplates;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AccessController extends Controller
{
    public function index()
    {
        return ['roles' => DB::table('platform_roles')->orderBy('name')->get()->map(function ($r) {
            $r->permissions = DB::table('platform_role_permissions')->where('role_id', $r->id)->pluck('permission');
            $r->users_count = DB::table('platform_user_roles')->where('role_id', $r->id)->count();
            unset($r->id);

            return $r;
        }), 'permissions' => collect(PlatformAccess::PERMISSIONS)->map(fn ($v, $k) => ['key' => $k, 'module' => $v[0], 'name' => $v[1], 'description' => $v[1]])->values(), 'templates' => PlatformAccess::TEMPLATES];
    }

    public function save(Request $r, PlatformCommand $cmd, ?string $role = null)
    {
        $old = $role ? DB::table('platform_roles')->where('public_id', $role)->first() : null;
        abort_if($role && ! $old, 404);

        return $cmd->run($r, 'access.manage', function () use ($r, $old, $role) {
            $d = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('platform_roles')->ignore($old?->id)], 'description' => 'nullable|string|max:1000', 'permissions' => 'present|array', 'permissions.*' => ['string', 'distinct', Rule::in(array_keys(PlatformAccess::PERMISSIONS))], 'version' => $role ? 'required|integer|min:1' : 'nullable|integer']);
            PlatformAccess::validateDependencies($d['permissions']);
            foreach ($d['permissions'] as $key) {
                app(PlatformAccess::class)->authorize($r->user(), $key);
            }$old = $role ? DB::table('platform_roles')->where('public_id', $role)->lockForUpdate()->first() : null;
            if ($old) {
                abort_if((int) $old->version !== (int) $d['version'], 409, 'This role changed. Reload its permissions.');
                if (! $r->user()->is_superadmin && DB::table('platform_user_roles')->where('user_id', $r->user()->id)->where('role_id', $old->id)->exists()) {
                    abort_unless(in_array('access.manage', $d['permissions']), 422, 'Do not remove your own role-management access.');
                }
            }$uuid = $old?->public_id ?? (string) Str::uuid();
            $row = ['name' => $d['name'], 'description' => $d['description'] ?? null, 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                $id = $old->id;
                DB::table('platform_roles')->where('id', $id)->update($row);
            } else {
                $id = DB::table('platform_roles')->insertGetId($row + ['public_id' => $uuid, 'created_at' => now()]);
            }DB::table('platform_role_permissions')->where('role_id', $id)->delete();
            foreach ($d['permissions'] as $key) {
                DB::table('platform_role_permissions')->insert(['role_id' => $id, 'permission' => $key]);
            }Audit::record('platform.role_saved', null, $uuid, ['permissions' => $d['permissions']]);

            return ['public_id' => $uuid, 'message' => 'Platform role saved.'];
        });
    }

    public function remove(Request $r, string $role, PlatformCommand $cmd)
    {
        return $cmd->run($r, 'access.manage', function () use ($role) {
            $row = DB::table('platform_roles')->where('public_id', $role)->first();
            abort_unless($row, 404);
            abort_if(DB::table('platform_user_roles')->where('role_id', $row->id)->exists(), 422, 'Reassign the staff using this role before deleting it.');
            DB::table('platform_role_permissions')->where('role_id', $row->id)->delete();
            DB::table('platform_roles')->where('id', $row->id)->delete();

            return ['message' => 'Unused role deleted.'];
        });
    }

    public function assignPlatform(Request $r, string $user, PlatformCommand $cmd)
    {
        $d = $r->validate(['roles' => 'present|array', 'roles.*' => 'uuid|distinct|exists:platform_roles,public_id']);

        return $cmd->run($r, 'access.manage', function () use ($user, $r, $d) {
            $u = DB::table('users')->where('public_id', $user)->lockForUpdate()->first();
            abort_unless($u, 404);
            abort_if($u->is_superadmin, 422, 'Root authority is explicit and is not changed by staff role assignment.');
            abort_if($u->id === $r->user()->id, 422, 'Another authorized administrator must change your staff assignments.');
            $roles = DB::table('platform_roles')->whereIn('public_id', $d['roles'])->get();
            foreach (DB::table('platform_role_permissions')->whereIn('role_id', $roles->pluck('id'))->pluck('permission') as $p) {
                app(PlatformAccess::class)->authorize($r->user(), $p);
            }DB::table('platform_user_roles')->where('user_id', $u->id)->delete();
            foreach ($roles as $role) {
                DB::table('platform_user_roles')->insert(['user_id' => $u->id, 'role_id' => $role->id]);
            }Audit::record('platform.staff_roles_assigned', null, $user, ['roles' => $d['roles']]);

            return ['message' => 'Staff platform permissions updated.'];
        });
    }

    private function tenant(string $client): object
    {
        $t = DB::table('tenants')->where('public_id', $client)->first();
        abort_unless($t, 404);

        return $t;
    }

    public function tenantIndex(string $client)
    {
        $t = $this->tenant($client);

        return ['roles' => DB::table('roles')->where('tenant_id', $t->id)->orderBy('name')->get()->map(function ($r) {
            $r->permissions = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $r->id)->pluck('p.key');
            unset($r->id,$r->tenant_id);

            return $r;
        }), 'permissions' => DB::table('permissions')->get(['key', 'module', 'name', 'description']), 'templates' => RoleTemplates::all(), 'branches' => DB::table('branches')->where('tenant_id', $t->id)->whereNull('deleted_at')->get(['public_id', 'name']), 'members' => DB::table('tenant_users as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $t->id)->get(['u.public_id', 'u.name', 'u.email', 'm.is_owner'])->map(function ($u) use ($t) {
            $u->assignments = DB::table('user_role_assignments as a')->join('users as us', 'us.id', '=', 'a.user_id')->join('roles as r', 'r.id', '=', 'a.role_id')->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')->where('a.tenant_id', $t->id)->where('us.public_id', $u->public_id)->get(['r.public_id as role', 'r.name as role_name', 'b.public_id as branch', 'b.name as branch_name']);

            return $u;
        })];
    }

    public function tenantSave(Request $r, string $client, PlatformCommand $cmd, ?string $role = null)
    {
        $t = $this->tenant($client);
        $old = $role ? DB::table('roles')->where('tenant_id', $t->id)->where('public_id', $role)->first() : null;
        abort_if($role && ! $old, 404);

        return $cmd->run($r, 'client_access.manage', function () use ($r, $old, $role, $t) {
            $d = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('tenant_id', $t->id)->ignore($old?->id)], 'permissions' => 'present|array', 'permissions.*' => 'string|distinct|exists:permissions,key', 'version' => $role ? 'required|integer|min:1' : 'nullable|integer']);
            DB::table('tenants')->where('id', $t->id)->lockForUpdate()->first();
            $old = $role ? DB::table('roles')->where('tenant_id', $t->id)->where('public_id', $role)->lockForUpdate()->first() : null;
            if ($old) {
                abort_if((int) $old->version !== (int) $d['version'], 409, 'This role changed. Reload before saving.');
            } $uuid = $old?->public_id ?? (string) Str::uuid();
            $id = $old?->id;
            if ($id) {
                $cashierPermission = DB::table('permissions')->where('key', 'sales.create')->value('id');
                $already = DB::table('role_permissions')->where('role_id', $id)->where('permission_id', $cashierPermission)->exists();
                abort_if(! $already && in_array('sales.create', $d['permissions']) && DB::table('user_role_assignments')->where('tenant_id', $t->id)->where('role_id', $id)->exists(), 422, 'Assign a new cashier-capable role individually so seat limits can be checked.');
                DB::table('roles')->where('id', $id)->update(['name' => $d['name'], 'version' => $old->version + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('roles')->insertGetId(['tenant_id' => $t->id, 'public_id' => $uuid, 'name' => $d['name'], 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }DB::table('role_permissions')->where('role_id', $id)->delete();
            foreach (DB::table('permissions')->whereIn('key', $d['permissions'])->pluck('id') as $p) {
                DB::table('role_permissions')->insert(['role_id' => $id, 'permission_id' => $p]);
            }Audit::record('platform.tenant_role_saved', $t->id, $uuid, ['permissions' => $d['permissions']]);

            return ['message' => 'Client role saved.', 'public_id' => $uuid];
        });
    }

    public function tenantRemove(Request $r, string $client, string $role, PlatformCommand $cmd)
    {
        return $cmd->run($r, 'client_access.manage', function () use ($client, $role) {
            $t = $this->tenant($client);
            DB::table('tenants')->where('id', $t->id)->lockForUpdate()->first();
            $row = DB::table('roles')->where('tenant_id', $t->id)->where('public_id', $role)->first();
            abort_unless($row, 404);
            abort_if(DB::table('user_role_assignments')->where('tenant_id', $t->id)->where('role_id', $row->id)->exists() || DB::table('tenant_invitations')->where('tenant_id', $t->id)->where('role_id', $row->id)->exists(), 422, 'This role is referenced by employees or invitation history. Duplicate or edit it instead.');
            DB::table('roles')->where('id', $row->id)->delete();

            return ['message' => 'Unused client role deleted.'];
        });
    }

    public function membership(Request $r, string $client, PlatformCommand $cmd)
    {
        $t = $this->tenant($client);
        $d = $r->validate(['user' => 'required|uuid|exists:users,public_id', 'action' => 'required|in:assign,remove', 'role' => 'required_if:action,assign|nullable|uuid', 'branch' => 'nullable|uuid']);

        return $cmd->run($r, 'client_access.manage', function () use ($t, $d) {
            DB::table('tenants')->where('id', $t->id)->lockForUpdate()->first();
            $u = DB::table('users')->where('public_id', $d['user'])->first();
            $member = DB::table('tenant_users')->where('tenant_id', $t->id)->where('user_id', $u->id)->first();
            abort_if($member?->is_owner, 422, 'Owner authority cannot be removed or replaced through employee roles.');
            if ($d['action'] === 'remove') {
                DB::table('user_role_assignments')->where('tenant_id', $t->id)->where('user_id', $u->id)->delete();
                DB::table('tenant_users')->where('tenant_id', $t->id)->where('user_id', $u->id)->delete();
            } else {
                $role = DB::table('roles')->where('tenant_id', $t->id)->where('public_id', $d['role'])->first();
                abort_unless($role, 404, 'Client role not found.');
                $branch = empty($d['branch']) ? null : DB::table('branches')->where('tenant_id', $t->id)->where('public_id', $d['branch'])->whereNull('deleted_at')->value('id');
                abort_if(! empty($d['branch']) && ! $branch, 404, 'Branch not in this client.');
                if (! $member) {
                    $pending = DB::table('tenant_invitations')->where('tenant_id', $t->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
                    app(Entitlements::class)->assertLimit($t->id, 'users', DB::table('tenant_users')->where('tenant_id', $t->id)->count() + $pending);
                    DB::table('tenant_users')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
                }app(InvitationService::class)->assertCashierLimit($t->id, $role->id, $u->id, true);
                DB::table('user_role_assignments')->where('tenant_id', $t->id)->where('user_id', $u->id)->delete();
                DB::table('user_role_assignments')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_id' => $role->id, 'branch_id' => $branch]);
            }Audit::record('platform.membership_'.$d['action'], $t->id, $d['user']);

            return ['message' => 'Client membership updated.'];
        });
    }
}
