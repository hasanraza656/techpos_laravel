<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountSecurity;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function sessions(Request $r)
    {
        return ['sessions' => DB::table('sessions')->where('user_id', $r->user()->id)->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)->orderByDesc('last_activity')->get()->map(fn ($s) => ['public_id' => AccountSecurity::sessionHash($s->id), 'ip' => $s->ip_address, 'user_agent' => $s->user_agent, 'last_active_at' => gmdate('c', $s->last_activity), 'is_current' => hash_equals($s->id, $r->session()->getId())]),
            'devices' => DB::table('devices')->join('tenant_users', function ($j) {
                $j->on('tenant_users.tenant_id', '=', 'devices.tenant_id')->on('tenant_users.user_id', '=', 'devices.user_id');
            })->join('tenants', 'tenants.id', '=', 'devices.tenant_id')->where('devices.user_id', $r->user()->id)->select('devices.public_id', 'devices.name', 'devices.last_seen_at', 'devices.revoked_at', 'tenants.name as business_name')->orderByDesc('last_seen_at')->limit(100)->get(),
            'recent_logins' => DB::table('login_events')->where('user_id', $r->user()->id)->orderByDesc('id')->limit(20)->select('ip', 'user_agent', 'suspicious', 'created_at')->get()];
    }

    public function revokeSession(Request $r, string $session)
    {
        $r->validate(['password' => 'required|current_password:web']);
        $rows = DB::table('sessions')->where('user_id', $r->user()->id)->get();
        $found = $rows->first(fn ($s) => hash_equals(AccountSecurity::sessionHash($s->id), $session));
        abort_unless($found, 404);
        abort_if(hash_equals($found->id, $r->session()->getId()), 422, 'Use Sign out to end your current session.');
        AccountSecurity::revoke($found->id, $r->user()->id);
        DB::table('devices')->where('user_id', $r->user()->id)->where('session_hash', $session)->update(['revoked_at' => now()]);
        $r->user()->forceFill(['remember_token' => Str::random(60)])->save();
        Audit::record('auth.session_revoked', null, $session);

        return ['message' => 'Session revoked. Remembered sign-ins have also been invalidated.'];
    }

    public function revokeOthers(Request $r)
    {
        $r->validate(['password' => 'required|current_password:web']);
        DB::transaction(function () use ($r) {
            foreach (DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->pluck('id') as $id) {
                AccountSecurity::revoke($id, $r->user()->id);
            }
            DB::table('devices')->where('user_id', $r->user()->id)->where('session_hash', '!=', AccountSecurity::sessionHash($r->session()->getId()))->update(['revoked_at' => now()]);
            $r->user()->forceFill(['remember_token' => Str::random(60)])->save();
            Audit::record('auth.other_sessions_revoked');
        });

        return ['message' => 'Other sessions revoked.'];
    }

    public function revokeDevice(Request $r, string $device)
    {
        $r->validate(['password' => 'required|current_password:web']);
        $d = DB::table('devices')->where('public_id', $device)->where('user_id', $r->user()->id)->first();
        abort_unless($d, 404);
        abort_if($d->session_hash && hash_equals($d->session_hash, AccountSecurity::sessionHash($r->session()->getId())), 422, 'Use Sign out to end the session on this device.');
        DB::transaction(function () use ($r, $d) {
            foreach (DB::table('sessions')->where('user_id', $r->user()->id)->get() as $s) {
                if ($d->session_hash && hash_equals($d->session_hash, AccountSecurity::sessionHash($s->id))) {
                    AccountSecurity::revoke($s->id, $r->user()->id);
                }
            }DB::table('devices')->where('id', $d->id)->update(['revoked_at' => now()]);
            $r->user()->forceFill(['remember_token' => Str::random(60)])->save();
            Audit::record('auth.device_revoked', $d->tenant_id, $d->public_id);
        });

        return ['message' => 'Device access revoked. A new sign-in is required.'];
    }
}
