<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\TenantService;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $r, TenantService $tenants)
    {
        if (is_string($r->input('email'))) {
            $r->merge(['email' => strtolower(trim($r->input('email')))]);
        }
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:254', 'unique:users', 'not_regex:/[\r\n]/'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()], 'business_name' => 'required|string|max:150']);
        $user = DB::transaction(function () use ($data, $tenants) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $tenants->create($user, $data['business_name']);

            return $user;
        });
        event(new Registered($user));
        Auth::login($user);
        $r->session()->regenerate();
        Audit::record('auth.registered', null, $user->public_id);

        return response()->json(['user' => $user->fresh()], 201);
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => ['required', 'email', 'not_regex:/[\r\n]/'], 'password' => 'required|string', 'remember' => 'sometimes|boolean']);
        abort_if(Auth::guard('web')->check(), 422, 'Sign out before signing in with another account.');
        $user = User::where('email', strtolower($data['email']))->first();
        // Always run one hash check so response timing does not reveal whether the email is registered.
        $passwordOk = Hash::check($data['password'], $user?->password ?? '$2y$12$hE5PjhkyQJ7bi5h/vYQPOOhcLtmfvwI1G0jCwVK.sX7I9OYTQTPgO');
        if (! $user || ! $passwordOk || ($user->status ?? 'active') !== 'active') {
            Audit::record('auth.login_failed', null, null, ['email_hash' => hash('sha256', strtolower($data['email']))]);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if ($user->two_factor_confirmed_at) {
            $r->session()->regenerate();
            $r->session()->put(['login.challenge_id' => $user->id, 'login.challenge_expires' => now()->addMinutes(5)->timestamp, 'login.challenge_remember' => $data['remember'] ?? false, 'login.challenge_attempts' => 0]);
            $r->session()->put('login.challenge_password_fingerprint', hash_hmac('sha256', $user->password, config('app.key')));

            return ['two_factor_required' => true];
        }
        Auth::guard('web')->login($user, $data['remember'] ?? false);
        $r->session()->regenerate();
        Audit::record('auth.login', null, $r->user()->public_id);
        app(AccountSecurity::class)->recordLogin($r->user(), $r);

        return ['user' => app(\App\Domain\Platform\PlatformAccess::class)->identity($r->user())];
    }

    public function logout(Request $r)
    {
        Audit::record('auth.logout');
        AccountSecurity::revoke($r->session()->getId(), $r->user()->id);
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $r)
    {
        return ['user' => app(\App\Domain\Platform\PlatformAccess::class)->identity($r->user()), 'tenants' => DB::table('tenants')->join('tenant_users', 'tenant_users.tenant_id', '=', 'tenants.id')->where('tenant_users.user_id', $r->user()->id)->whereNull('tenants.deleted_at')->select('tenants.public_id', 'tenants.name', 'tenants.status', 'tenant_users.is_owner')->get()];
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => ['required', 'email', 'not_regex:/[\r\n]/']]);
        Password::sendResetLink($r->only('email'));

        return ['message' => 'If this email has an account, a reset link has been sent.'];
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required|string', 'email' => ['required', 'email', 'not_regex:/[\r\n]/'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            foreach (DB::table('sessions')->where('user_id', $user->id)->pluck('id') as $session) {
                AccountSecurity::revoke($session, $user->id);
            }
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return ['message' => __($status)];
    }

    public function resend(Request $r)
    {
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        return ['message' => 'Verification link sent.'];
    }
}
