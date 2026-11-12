<?php

namespace App\Http\Middleware;

use App\Domain\Platform\PlatformAccess;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;

final class RequirePlatformPermission
{
    public function handle(Request $r, Closure $next, string $permission)
    {
        app(PlatformAccess::class)->authorize($r->user(), $permission);
        if ($r->isMethod('GET') && in_array($permission, ['clients.view', 'inventory.view', 'inventory.cost', 'client_sales.view', 'users.view', 'client_access.view', 'audit.view'])) {
            Audit::record('platform.data_access', null, $permission, ['route' => $r->route()?->uri(), 'client' => $r->query('client'), 'filter_keys' => array_keys($r->query())]);
        }

        return $next($r);
    }
}
