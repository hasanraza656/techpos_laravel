<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSuperadmin
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user() && app(\App\Domain\Platform\PlatformAccess::class)->enter($r->user()), 403, 'Platform access required.');

        return $next($r);
    }
}
