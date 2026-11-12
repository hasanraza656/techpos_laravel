<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantMedia;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class MediaController extends Controller
{
    public function show(Request $r, string $media)
    {
        $row = DB::table('tenant_media')->where('public_id', $media)->first();
        abort_unless($row, 404);
        $tenant = Tenant::whereKey($row->tenant_id)->where('status', 'active')->first();
        $member = DB::table('tenant_users')->where('tenant_id', $row->tenant_id)->where('user_id', $r->user()->id)->first();
        abort_unless($tenant && $member && $r->user()->can('view', $tenant), 404);
        if ($row->kind === 'product' && ! $member->is_owner) {
            // Products are shared within a business; require a catalog grant in at least one active branch.
            $canView = DB::table('user_role_assignments as a')->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('a.tenant_id', $tenant->id)->where('a.user_id', $r->user()->id)->where('p.key', 'products.view')
                ->where(fn ($q) => $q->whereNull('a.branch_id')->orWhereIn('a.branch_id', DB::table('branches')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->select('id')))->exists();
            abort_unless($canView, 403);
        }
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return response()->file(Storage::disk('local')->path($row->path), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function logo(Request $r, TenantContext $c, TenantMedia $media)
    {
        if ($r->isMethod('DELETE')) {
            $media->remove($c);

            return response()->noContent();
        }
        $r->validate(['image' => 'required|file']);

        return $media->store($c, $r->file('image'));
    }

    public function product(Request $r, string $product, TenantContext $c, TenantMedia $media)
    {
        $p = DB::table('products')->where('tenant_id', $c->tenant->id)->where('public_id', $product)->whereNull('deleted_at')->first();
        abort_unless($p, 404);
        if ($r->isMethod('DELETE')) {
            $media->remove($c, $p);

            return response()->noContent();
        }
        $r->validate(['image' => 'required|file']);

        return $media->store($c, $r->file('image'), $p);
    }
}
