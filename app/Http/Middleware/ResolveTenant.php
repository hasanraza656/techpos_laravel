<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = Tenant::where('public_id', $request->header('X-Tenant-ID'))->where('status', 'active')->first();
        abort_unless($tenant, 404, 'Business not found.');
        $member = DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->first();
        abort_unless($member && $request->user()->can('view', $tenant), 403, 'You do not belong to this business.');
        $context = app(TenantContext::class);
        $context->tenant = $tenant;
        $context->user = $request->user();
        $context->owner = (bool) $member->is_owner;

        return $next($request);
    }
}
