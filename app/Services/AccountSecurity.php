<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\NewSignIn;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccountSecurity
{
    public static function revoke(string $sessionId, int $userId): void
    {
        DB::table('revoked_sessions')->updateOrInsert(['session_hash' => self::sessionHash($sessionId)], ['user_id' => $userId, 'expires_at' => now()->addMinutes(config('session.lifetime') + 5), 'created_at' => now()]);
        DB::table('sessions')->where('id', $sessionId)->where('user_id', $userId)->delete();
    }

    public static function sessionHash(string $session): string
    {
        return hash_hmac('sha256', $session, config('app.key'));
    }

    public function recordLogin(User $user, Request $r): void
    {
        $ua = mb_substr((string) $r->userAgent(), 0, 500);
        $fingerprint = hash_hmac('sha256', $r->ip().'|'.$ua, config('app.key'));
        $known = DB::table('login_events')->where('user_id', $user->id);
        $new = $known->exists() && ! (clone $known)->where('fingerprint', $fingerprint)->exists();
        DB::table('login_events')->insert(['user_id' => $user->id, 'fingerprint' => $fingerprint, 'ip' => $r->ip(), 'user_agent' => $ua, 'suspicious' => $new, 'created_at' => now()]);
        if ($new) {
            Audit::record('auth.unfamiliar_login', null, $user->public_id);
            $user->notify(new NewSignIn((string) $r->ip(), now()->toIso8601String()));
        }
    }
}
