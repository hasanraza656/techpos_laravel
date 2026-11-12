<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\TwoFactor;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function status(Request $r)
    {
        return ['enabled' => (bool) $r->user()->two_factor_confirmed_at, 'remaining_recovery_codes' => count($r->user()->two_factor_recovery_codes ?? [])];
    }

    public function setup(Request $r, TwoFactor $two)
    {
        $r->validate(['password' => 'required|current_password:web']);
        $result = DB::transaction(function () use ($r, $two) {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($u->two_factor_confirmed_at, 422, 'Two-factor authentication is already enabled.');
            $secret = $two->totp()->generateSecretKey(32);
            $u->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null, 'two_factor_setup_at' => now(), 'two_factor_last_counter' => null])->save();
            Audit::record('auth.two_factor_setup');

            return ['secret' => $secret, 'qr_url' => $two->totp()->getQRCodeUrl('Tech POS', $u->email, $secret)];
        });

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['code' => 'required|string|max:100']);
        $codes = DB::transaction(function () use ($r, $d, $two) {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($u->two_factor_confirmed_at || ! $u->two_factor_setup_at || $u->two_factor_setup_at->lt(now()->subMinutes(10)), 422, 'Start a new authenticator setup.');
            $two->consume($u, $d['code']);
            $u->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_setup_at' => null, 'remember_token' => Str::random(60)])->save();
            foreach (DB::table('sessions')->where('user_id', $u->id)->where('id', '!=', $r->session()->getId())->pluck('id') as $session) {
                AccountSecurity::revoke($session, $u->id);
            }
            Audit::record('auth.two_factor_enabled');

            return $two->recoveryCodes($u);
        });

        return response()->json(['recovery_codes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function disable(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['password' => 'required|current_password:web', 'code' => 'required|string|max:100']);
        DB::transaction(function () use ($r, $d, $two) {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->two_factor_confirmed_at, 422, 'Two-factor authentication is not enabled.');
            $two->consume($u, $d['code']);
            $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_setup_at' => null, 'two_factor_last_counter' => null])->save();
            Audit::record('auth.two_factor_disabled');
        });

        return ['message' => 'Two-factor authentication disabled.'];
    }

    public function regenerate(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['password' => 'required|current_password:web', 'code' => 'required|string|max:100']);
        $codes = DB::transaction(function () use ($r, $d, $two) {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->two_factor_confirmed_at, 422);
            $two->consume($u, $d['code']);
            Audit::record('auth.two_factor_recovery_regenerated');

            return $two->recoveryCodes($u);
        });

        return response()->json(['recovery_codes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function challenge(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['code' => 'required|string|max:100']);
        $id = $r->session()->get('login.challenge_id');
        $expires = $r->session()->get('login.challenge_expires', 0);
        $attempts = $r->session()->get('login.challenge_attempts', 0);
        abort_unless($id && $expires > now()->timestamp && $attempts < 5, 422, 'Your sign-in challenge expired. Sign in again.');
        $r->session()->put('login.challenge_attempts', $attempts + 1);
        try {
            $u = DB::transaction(function () use ($id, $two, $d, $r) {
                $u = User::whereKey($id)->lockForUpdate()->firstOrFail();
                abort_unless(($u->status??'active')==='active',403,'This account is inactive.');
                abort_unless($u->two_factor_confirmed_at, 422, 'Restart sign-in.');
                abort_unless(hash_equals(hash_hmac('sha256', $u->password, config('app.key')), (string) $r->session()->get('login.challenge_password_fingerprint')), 422, 'Your password changed. Restart sign-in.');
                $two->consume($u, $d['code']);

                return $u;
            });
        } catch (ValidationException $e) {
            Audit::record('auth.two_factor_failed');
            throw $e;
        }
        $remember = (bool) $r->session()->get('login.challenge_remember', false);
        $r->session()->forget(['login.challenge_id', 'login.challenge_expires', 'login.challenge_attempts', 'login.challenge_remember', 'login.challenge_password_fingerprint']);
        Auth::guard('web')->login($u, $remember);
        $r->session()->regenerate();
        Audit::record('auth.login', null, $u->public_id);
        app(AccountSecurity::class)->recordLogin($u, $r);

        return ['user' => app(\App\Domain\Platform\PlatformAccess::class)->identity($u)];
    }
}
