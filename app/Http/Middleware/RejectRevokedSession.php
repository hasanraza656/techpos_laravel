<?php

namespace App\Http\Middleware;

use App\Services\AccountSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RejectRevokedSession
{
    public function handle(Request $r, Closure $next)
    {
        if(!DB::table('users')->where('id',$r->user()->id)->where('status','active')->exists()){
            Auth::guard('web')->logout();$r->session()->invalidate();$r->session()->regenerateToken();
            abort(401,'This account is inactive. Contact your platform administrator.');
        }
        if (DB::table('revoked_sessions')->where('user_id', $r->user()->id)->where('session_hash', AccountSecurity::sessionHash($r->session()->getId()))->where('expires_at', '>', now())->exists()) {
            Auth::guard('web')->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            abort(401, 'This session has been revoked. Sign in again.');
        }

        return $next($r);
    }
}
