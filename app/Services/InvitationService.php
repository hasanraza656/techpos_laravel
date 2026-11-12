<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\BusinessInvitation;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvitationService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['invitation' => $message]);
    }

    public function issue(TenantContext $c, string $email, string $roleId, ?string $branchId): object
    {
        return DB::transaction(function () use ($c, $email, $roleId, $branchId) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $role = DB::table('roles')->where('tenant_id', $c->tenant->id)->where('public_id', $roleId)->first();
            abort_unless($role, 404);
            foreach (DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_id', $role->id)->pluck('key') as $key) {
                abort_unless($c->allows($key), 403, 'You cannot grant a permission you do not have.');
            }
            $branch = null;
            if ($branchId) {
                $branch = DB::table('branches')->where('tenant_id', $c->tenant->id)->where('public_id', $branchId)->whereNull('deleted_at')->value('id');
                abort_unless($branch, 404);
            }
            $email = mb_strtolower(trim($email));
            if (DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')->where('tenant_id', $c->tenant->id)->where('email', $email)->exists()) {
                $this->fail('This person already belongs to the business.');
            }
            if (DB::table('tenant_invitations')->where('tenant_id', $c->tenant->id)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                $this->fail('There is already an open invitation for this email. Revoke it before sending another.');
            }
            $pending = DB::table('tenant_invitations')->where('tenant_id', $c->tenant->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
            app(Entitlements::class)->assertLimit($c->tenant->id, 'users', DB::table('tenant_users')->where('tenant_id', $c->tenant->id)->count() + $pending);
            $this->assertCashierLimit($c->tenant->id, $role->id, null, true);
            $token = Str::random(64);
            $uuid = (string) Str::uuid();
            DB::table('tenant_invitations')->insert(['public_id' => $uuid, 'tenant_id' => $c->tenant->id, 'invited_by' => $c->user->id, 'email' => $email, 'role_id' => $role->id, 'branch_id' => $branch, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(72), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => Notification::route('mail', $email)->notify(new BusinessInvitation($c->tenant->name, $token)));
            Audit::record('invitation.created', $c->tenant->id, $uuid, ['role' => $roleId, 'branch' => $branchId]);

            return (object) ['public_id' => $uuid, 'message' => 'Invitation sent. It expires in 72 hours.'];
        });
    }

    public function find(string $token, bool $lock = false): object
    {
        $q = DB::table('tenant_invitations')->where('token_hash', hash('sha256', $token));
        $i = $lock ? $q->lockForUpdate()->first() : $q->first();
        abort_unless($i && ! $i->accepted_at && ! $i->revoked_at && now()->lt($i->expires_at), 410, 'This invitation has expired, been revoked, or already been accepted.');
        abort_unless(DB::table('tenants')->where('id', $i->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists(), 410, 'This business is unavailable.');

        return $i;
    }

    public function accept(string $token, User $user): string
    {
        return DB::transaction(function () use ($token, $user) {
            $preview = $this->find($token);
            // Lock order is tenant then invitation everywhere.
            DB::table('tenants')->where('id', $preview->tenant_id)->lockForUpdate()->first();
            $i = $this->find($token, true);
            abort_unless(hash_equals(mb_strtolower($i->email), mb_strtolower($user->email)), 403, 'Sign in using the email address that received this invitation.');
            abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email before accepting this invitation.');
            if (DB::table('tenant_users')->where('tenant_id', $i->tenant_id)->where('user_id', $user->id)->exists()) {
                $this->fail('You already belong to this business.');
            }
            app(Entitlements::class)->assertLimit($i->tenant_id, 'users', DB::table('tenant_users')->where('tenant_id', $i->tenant_id)->count());
            $this->assertCashierLimit($i->tenant_id, $i->role_id);
            if ($i->branch_id) {
                abort_unless(DB::table('branches')->where('tenant_id', $i->tenant_id)->where('id', $i->branch_id)->whereNull('deleted_at')->exists(), 410, 'The invited branch is no longer available.');
            }
            DB::table('tenant_users')->insert(['tenant_id' => $i->tenant_id, 'user_id' => $user->id, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_role_assignments')->insert(['tenant_id' => $i->tenant_id, 'user_id' => $user->id, 'role_id' => $i->role_id, 'branch_id' => $i->branch_id]);
            DB::table('tenant_invitations')->where('id', $i->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
            Audit::record('invitation.accepted', $i->tenant_id, $i->public_id);

            return DB::table('tenants')->where('id', $i->tenant_id)->value('public_id');
        });
    }

    public function assertCashierLimit(int $tenantId, int $roleId, ?int $excludeUser = null, bool $includePending = false): void
    {
        $cashierRoleIds = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('permissions.key', 'sales.create')->pluck('role_id');
        if (! $cashierRoleIds->contains($roleId)) {
            return;
        }
        $q = DB::table('user_role_assignments')->where('tenant_id', $tenantId)->whereIn('role_id', $cashierRoleIds);
        if ($excludeUser) {
            $q->where('user_id', '!=', $excludeUser);
        }
        $count = $q->distinct()->count('user_id');
        if ($includePending) {
            $count += DB::table('tenant_invitations')->where('tenant_id', $tenantId)->whereIn('role_id', $cashierRoleIds)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
        }
        app(Entitlements::class)->assertLimit($tenantId, 'cashiers', $count);
    }
}
