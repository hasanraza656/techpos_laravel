<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Platform\Filters;
use App\Domain\Platform\PlatformAccess;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class InventoryController extends Controller
{
    public function index(Request $r)
    {
        $d = Filters::read($r);
        $cost = in_array('inventory.cost', app(PlatformAccess::class)->permissions($r->user()));
        $q = DB::table('products as p')->join('tenants as t', 't.id', '=', 'p.tenant_id')->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.product_id', '=', 'p.id')->on('sb.tenant_id', '=', 'p.tenant_id'))
            ->leftJoin('branches as b', 'b.id', '=', 'sb.branch_id')->leftJoin('warehouses as w', 'w.id', '=', 'sb.warehouse_id')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')->leftJoin('tenant_media as m', fn ($j) => $j->on('m.product_id', '=', 'p.id')->on('m.tenant_id', '=', 'p.tenant_id'))->whereNull('p.deleted_at');
        Filters::client($q, $d);
        Filters::search($q, $d, ['p.name', 'p.sku', 't.name', 'c.name']);
        if (! empty($d['branch'])) {
            $q->where('b.public_id', $d['branch']);
        }if (! empty($d['warehouse'])) {
            $q->where('w.public_id', $d['warehouse']);
        }
        if (($d['status'] ?? '') === 'low') {
            $q->where('p.track_stock', true)->whereRaw('COALESCE(sb.quantity,0)-COALESCE(sb.reserved_quantity,0)<=p.reorder_level');
        } elseif (($d['status'] ?? '') === 'out') {
            $q->where('p.track_stock', true)->whereRaw('COALESCE(sb.quantity,0)-COALESCE(sb.reserved_quantity,0)<=0');
        } elseif (($d['status'] ?? '') === 'inactive') {
            $q->where('p.active', false);
        } else {
            $q->where('p.active', true);
        }
        $columns = ['p.public_id', 'p.name', 'p.sku', 'p.type', 'p.unit', 'p.selling_price', 'p.reorder_level', 'p.track_stock', 'p.active', 't.public_id as client', 't.name as business_name', 't.currency', 'b.public_id as branch', 'b.name as branch_name', 'w.public_id as warehouse', 'w.name as warehouse_name', 'c.name as category', 'm.public_id as image_id', DB::raw('COALESCE(sb.quantity,0) as quantity'), DB::raw('COALESCE(sb.reserved_quantity,0) as reserved_quantity')];
        if ($cost) {
            $columns[] = 'p.cost_price';
            $columns[] = DB::raw('COALESCE(sb.cost_value,0) as stock_value');
        }
        $page = $q->select($columns)->orderBy('t.name')->orderBy('p.name')->orderBy('p.id')->orderBy('sb.id')->paginate($d['per_page'] ?? 25);
        $page->getCollection()->transform(function ($p) use ($cost) {
            $p->selling_price = bcadd((string) $p->selling_price, '0', 2);
            $p->quantity = bcadd((string) $p->quantity, '0', 3);
            $p->reserved_quantity = bcadd((string) $p->reserved_quantity, '0', 3);
            $p->available = bcsub($p->quantity, $p->reserved_quantity, 3);
            $p->reorder_level = bcadd((string) $p->reorder_level, '0', 3);
            $p->image = $p->image_id ? ['url' => '/api/v1/platform/media/'.$p->image_id] : null;
            unset($p->image_id);
            if ($cost) {
                $p->cost_price = bcadd((string) $p->cost_price, '0', 4);
                $p->stock_value = bcadd((string) $p->stock_value, '0', 4);
            }

            return $p;
        });

        return $page;
    }

    public function movements(Request $r, string $product)
    {
        $d = Filters::read($r);
        $p = DB::table('products')->where('public_id', $product)->first();
        abort_unless($p, 404);
        $cost = in_array('inventory.cost', app(PlatformAccess::class)->permissions($r->user()));
        $q = DB::table('inventory_movements as m')->join('branches as b', 'b.id', '=', 'm.branch_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->leftJoin('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $p->tenant_id)->where('m.product_id', $p->id);
        if (! empty($d['branch'])) {
            $q->where('b.public_id', $d['branch']);
        }$fields = ['m.public_id', 'm.type', 'm.quantity', 'm.reason', 'm.source_type', 'm.source_id', 'm.created_at', 'b.name as branch_name', 'w.name as warehouse_name', 'u.name as user_name'];
        if ($cost) {
            $fields[] = 'm.cost_value';
        }

        return $q->select($fields)->orderByDesc('m.id')->paginate($d['per_page'] ?? 25);
    }

    public function media(string $media)
    {
        $row = DB::table('tenant_media')->where('public_id', $media)->where('kind', 'product')->first();
        abort_unless($row && Storage::disk('local')->exists($row->path), 404);
        Audit::record('platform.product_image_read', $row->tenant_id, $media);

        return response()->file(Storage::disk('local')->path($row->path), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store']);
    }

    public function sales(Request $r)
    {
        $d = Filters::read($r);
        $q = DB::table('sales as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->join('branches as b', 'b.id', '=', 's.branch_id')->join('users as u', 'u.id', '=', 's.cashier_id');
        Filters::client($q, $d);
        Filters::search($q, $d, ['s.receipt_number', 't.name']);
        if (! empty($d['branch'])) {
            $q->where('b.public_id', $d['branch']);
        }

        return $q->select('s.public_id', 's.receipt_number', 's.finalized_at', 's.currency', 's.total', 's.tax_total', 's.channel', 't.public_id as client', 't.name as business_name', 'b.name as branch_name', 'u.name as cashier')->orderByDesc('s.id')->paginate($d['per_page'] ?? 25);
    }
}
