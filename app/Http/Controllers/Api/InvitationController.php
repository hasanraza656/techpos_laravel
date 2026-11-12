<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InvitationService;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function index(TenantContext $c)
    {
        $c->authorize('employees.manage');

        return DB::table('tenant_invitations')->where('tenant_id', $c->tenant->id)->select('public_id', 'email', 'expires_at', 'accepted_at', 'revoked_at', 'created_at')->latest('id')->limit(100)->get();
    }

    public function create(Request $r, TenantContext $c, InvitationService $s)
    {
        $c->authorize('employees.manage');
        $c->authorize('roles.manage');
        $d = $r->validate(['email' => ['required', 'email', 'max:254', 'not_regex:/[\r\n]/'], 'role' => 'required|uuid', 'branch' => 'nullable|uuid']);

        return response()->json($s->issue($c, $d['email'], $d['role'], $d['branch'] ?? null), 201);
    }

    public function revoke(string $invitation, TenantContext $c)
    {
        $c->authorize('employees.manage');
        DB::transaction(function () use ($invitation, $c) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $i = DB::table('tenant_invitations')->where('tenant_id', $c->tenant->id)->where('public_id', $invitation)->lockForUpdate()->first();
            abort_unless($i, 404);
            abort_if($i->accepted_at, 422, 'This invitation has already been accepted.');
            DB::table('tenant_invitations')->where('id', $i->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            Audit::record('invitation.revoked', $c->tenant->id, $invitation);
        });

        return ['message' => 'Invitation revoked.'];
    }

    public function preview(string $token, InvitationService $s)
    {
        $i = $s->find($token);

        return ['business_name' => DB::table('tenants')->where('id', $i->tenant_id)->value('name'), 'role_name' => DB::table('roles')->where('id', $i->role_id)->value('name'), 'expires_at' => $i->expires_at];
    }

    public function accept(Request $r, string $token, InvitationService $s)
    {
        return ['tenant' => $s->accept($token, $r->user()), 'message' => 'You have joined the business.'];
    }

    public function register(Request $r, string $token, InvitationService $s)
    {
        abort_if(Auth::guard('web')->check(), 422, 'Sign out before creating a different invited account, or accept using your existing account.');
        $i = $s->find($token);
        $d = $r->validate(['name' => 'required|string|max:100', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]]);
        abort_if(User::where('email', $i->email)->exists(), 422, 'An account already exists for this invitation. Sign in to accept it.');
        $user = DB::transaction(function () use ($i, $d, $s, $token) {
            $u = User::create(['email' => $i->email, 'name' => $d['name'], 'password' => $d['password']]);
            // The private invitation token proves possession of the invited email inbox.
            $u->forceFill(['email_verified_at' => now()])->save();
            $s->accept($token, $u);

            return $u;
        });
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        Audit::record('auth.invited_registration', null, $user->public_id);

        return response()->json(['user' => $user->fresh()], 201);
    }

    public function remove(Request $r, string $member, TenantContext $c)
    {
        $c->authorize('employees.manage');
        $r->validate(['password' => 'required|current_password:web']);
        DB::transaction(function () use ($member, $c) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $u = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')->where('tenant_id', $c->tenant->id)->where('users.public_id', $member)->select('users.id', 'tenant_users.is_owner')->first();
            abort_unless($u, 404);
            abort_if($u->is_owner, 422, 'Business ownership must be transferred before removing an owner.');
            abort_if($u->id === $c->user->id, 422, 'Ask a business owner to remove your membership.');
            if (! $c->owner) {
                $c->authorize('roles.manage');
                foreach (DB::table('user_role_assignments as a')->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('a.tenant_id', $c->tenant->id)->where('a.user_id', $u->id)->pluck('p.key') as $key) {
                    abort_unless($c->allows($key), 403, 'You cannot remove a more privileged member.');
                }
            }
            DB::table('tenant_users')->where('tenant_id', $c->tenant->id)->where('user_id', $u->id)->delete();
            DB::table('tenant_invitations')->where('tenant_id', $c->tenant->id)->where('invited_by', $u->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('devices')->where('tenant_id', $c->tenant->id)->where('user_id', $u->id)->delete();
            Audit::record('member.removed', $c->tenant->id, $member);
        });

        return ['message' => 'Business access removed. The person’s other memberships are preserved.'];
    }
}
