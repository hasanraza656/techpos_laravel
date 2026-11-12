<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\Decimal;
use App\Domain\Operations\OperationScope;
use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Services\TenantMedia;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function show(string $product, TenantContext $c)
    {
        $c->authorize('products.view');
        $p = DB::table('products')->where('tenant_id', $c->tenant->id)->where('public_id', $product)->whereNull('deleted_at')->first();
        abort_unless($p, 404);
        $p->barcodes = DB::table('product_barcodes')->where('tenant_id', $c->tenant->id)->where('product_id', $p->id)->get(['barcode', 'quantity']);
        $p->image = TenantMedia::present(DB::table('tenant_media')->where('tenant_id', $c->tenant->id)->where('product_id', $p->id)->first());
        foreach ($p->barcodes as $barcode) {
            $barcode->quantity = bcadd((string) $barcode->quantity, '0', 3);
        }
        $p->category = $p->category_id ? DB::table('product_categories')->where('id', $p->category_id)->value('public_id') : null;
        if (! $c->allows('products.view_cost')) {
            unset($p->cost_price);
        }foreach (['selling_price' => 2, 'wholesale_price' => 2, 'cost_price' => 4, 'tax_rate' => 4, 'reorder_level' => 3] as $field => $scale) {
            if (isset($p->$field)) {
                $p->$field = bcadd((string) $p->$field, '0', $scale);
            }
        }$p->price_history = DB::table('product_price_history')->where('tenant_id', $c->tenant->id)->where('product_id', $p->id)->orderByDesc('id')->limit(30)->get(['old_price', 'new_price', 'created_at']);
        unset($p->id,$p->tenant_id,$p->category_id);

        return $p;
    }

    public function index(Request $r, TenantContext $c, OperationScope $scope)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'warehouse' => 'required|uuid', 'q' => 'nullable|string|max:180', 'barcode' => 'nullable|string|max:100', 'category' => 'nullable|uuid', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'include_inactive' => 'sometimes|boolean']);
        $b = $scope->branch($d['branch'], 'products.view');
        $w = $scope->warehouse($d['warehouse'], $b->id);
        app(Entitlements::class)->authorize($c->tenant->id, 'inventory');
        $q = DB::table('products as p')->leftJoin('stock_balances as sb', function ($join) use ($w) {
            $join->on('sb.product_id', '=', 'p.id')->on('sb.tenant_id', '=', 'p.tenant_id')->where('sb.warehouse_id', $w->id);
        })->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->where('p.tenant_id', $c->tenant->id)->whereNull('p.deleted_at');
        if (! ($d['include_inactive'] ?? false)) {
            $q->where('p.active', true);
        } else {
            $c->authorize('products.edit');
        }
        if (! empty($d['barcode'])) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('product_barcodes as pb')->whereColumn('pb.product_id', 'p.id')->where('pb.tenant_id', $c->tenant->id)->where('pb.barcode', $d['barcode']));
        } elseif (! empty($d['q'])) {
            $term = addcslashes($d['q'], '%_\\');
            $q->where(function ($s) use ($term, $c) {
                $s->where('p.name', 'like', "%$term%")->orWhere('p.alternate_name', 'like', "%$term%")->orWhere('p.sku', 'like', "%$term%")->orWhere('p.brand', 'like', "%$term%")->orWhere('pc.name', 'like', "%$term%")->orWhereExists(fn ($s) => $s->selectRaw('1')->from('product_barcodes as pb')->whereColumn('pb.product_id', 'p.id')->where('pb.tenant_id', $c->tenant->id)->where('pb.barcode', 'like', "%$term%"));
            });
        }
        if (! empty($d['category'])) {
            $q->where('pc.public_id', $d['category']);
        }
        $fields = ['p.id', 'p.public_id', 'p.name', 'p.alternate_name', 'p.sku', 'p.brand', 'p.type', 'p.unit', 'p.selling_price', 'p.wholesale_price', 'p.tax_rate', 'p.tax_inclusive', 'p.track_stock', 'p.active', 'p.reorder_level', 'p.version', 'p.updated_at', 'pc.public_id as category', 'pc.name as category_name', DB::raw('COALESCE(sb.quantity,0) as stock_quantity')];
        if ($c->allows('products.view_cost', $b->id)) {
            $fields[] = 'p.cost_price';
        }
        $page = $q->select($fields)->orderBy('p.name')->orderBy('p.id')->paginate($d['per_page'] ?? 50);
        $barcodes = DB::table('product_barcodes')->where('tenant_id', $c->tenant->id)->whereIn('product_id', $page->getCollection()->pluck('id'))->get()->groupBy('product_id');
        $media = DB::table('tenant_media')->where('tenant_id', $c->tenant->id)->whereIn('product_id', $page->getCollection()->pluck('id'))->get()->keyBy('product_id');
        $page->getCollection()->transform(function ($p) use ($barcodes, $media) {
            $p->image = TenantMedia::present($media->get($p->id));
            foreach (['selling_price' => 2, 'wholesale_price' => 2, 'tax_rate' => 4, 'reorder_level' => 3, 'stock_quantity' => 3, 'cost_price' => 4] as $f => $scale) {
                if (isset($p->$f)) {
                    $p->$f = bcadd((string) $p->$f, '0', $scale);
                }
            }foreach (['tax_inclusive', 'track_stock', 'active'] as $f) {
                $p->$f = (bool) $p->$f;
            }$p->barcodes = ($barcodes->get($p->id) ?? collect())->map(fn ($b) => ['barcode' => $b->barcode, 'quantity' => bcadd((string) $b->quantity, '0', 3)])->values();
            unset($p->id);

            return $p;
        });

        return $page;
    }

    public function categories(Request $r, TenantContext $c)
    {
        $c->authorize('products.view');

        return DB::table('product_categories')->where('tenant_id', $c->tenant->id)->orderBy('name')->select('public_id', 'name')->get();
    }

    public function createCategory(Request $r, TenantContext $c)
    {
        $c->authorize('products.create');
        $d = $r->validate(['name' => ['required', 'string', 'max:120', Rule::unique('product_categories')->where('tenant_id', $c->tenant->id)]]);
        $uuid = (string) Str::uuid();
        DB::table('product_categories')->insert(['tenant_id' => $c->tenant->id, 'public_id' => $uuid, 'name' => $d['name'], 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('category.created', $c->tenant->id, $uuid);

        return response()->json(['public_id' => $uuid, 'name' => $d['name']], 201);
    }

    public function save(Request $r, TenantContext $c, ?string $product = null)
    {
        $c->authorize($product ? 'products.edit' : 'products.create');
        app(Entitlements::class)->authorize($c->tenant->id, 'inventory');
        $d = $r->validate(['name' => 'required|string|max:180', 'sku' => 'required|string|max:80', 'alternate_name' => 'nullable|string|max:180', 'brand' => 'nullable|string|max:100', 'category' => 'nullable|uuid', 'description' => 'nullable|string|max:5000', 'type' => 'required|in:standard,weighted,service', 'unit' => 'required|string|max:30', 'cost_price' => $product ? 'sometimes|string' : 'required|string', 'selling_price' => 'required|string', 'wholesale_price' => 'nullable|string', 'tax_rate' => 'required|string', 'tax_inclusive' => 'required|boolean', 'reorder_level' => 'required|string', 'active' => 'required|boolean', 'version' => 'required_with:product|sometimes|integer|min:1',
            'barcodes' => 'present|array|max:20', 'barcodes.*' => 'array:barcode,quantity', 'barcodes.*.barcode' => 'required|string|max:100|distinct', 'barcodes.*.quantity' => 'required|string']);
        if (isset($d['cost_price'])) {
            $d['cost_price'] = Decimal::input($d['cost_price'], 4);
        }
        $d['selling_price'] = Decimal::input($d['selling_price']);
        if (isset($d['wholesale_price'])) {
            $d['wholesale_price'] = Decimal::input($d['wholesale_price']);
        }$d['tax_rate'] = Decimal::input($d['tax_rate'], 4);
        $d['reorder_level'] = Decimal::input($d['reorder_level'], 3);
        if (bccomp($d['tax_rate'], '100', 4) > 0) {
            Decimal::fail('tax_rate', 'Tax rate must be between 0 and 100.');
        }

        return DB::transaction(function () use ($d, $c, $product) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $old = $product ? DB::table('products')->where('tenant_id', $c->tenant->id)->where('public_id', $product)->whereNull('deleted_at')->lockForUpdate()->first() : null;
            if ($product) {
                abort_unless($old, 404);
            }
            if ($old) {
                abort_unless(isset($d['version']) && (int) $d['version'] === (int) $old->version, 409, 'Product changed since you opened it. Reload before saving.');
            }
            if (DB::table('products')->where('tenant_id', $c->tenant->id)->where('sku', $d['sku'])->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists()) {
                Decimal::fail('sku', 'This SKU already exists.');
            }
            if ($d['active'] && (! $old || ! $old->active)) {
                app(Entitlements::class)->assertLimit($c->tenant->id, 'active_skus', DB::table('products')->where('tenant_id', $c->tenant->id)->where('active', true)->whereNull('deleted_at')->count());
            }
            if ($old && ! isset($d['cost_price'])) {
                $d['cost_price'] = bcadd((string) $old->cost_price, '0', 4);
            }
            if (! $old || bccomp($d['cost_price'], $old->cost_price, 4) !== 0) {
                $c->authorize('products.view_cost');
            }
            if ($old && (bccomp($d['selling_price'], $old->selling_price, 2) !== 0 || ($d['wholesale_price'] ?? null) !== ($old->wholesale_price === null ? null : bcadd((string) $old->wholesale_price, '0', 2)))) {
                $c->authorize('products.change_price');
            }
            if (bccomp($d['tax_rate'], $old?->tax_rate ?? '0', 4) !== 0 || $d['tax_inclusive'] !== ((bool) ($old?->tax_inclusive ?? false))) {
                $c->authorize('settings.tax');
            }
            if ($old && $d['type'] !== $old->type && DB::table('inventory_movements')->where('tenant_id', $c->tenant->id)->where('product_id', $old->id)->exists()) {
                Decimal::fail('type', 'Products with stock history cannot change stock type.');
            }
            $category = null;
            if (! empty($d['category'])) {
                $category = DB::table('product_categories')->where('tenant_id', $c->tenant->id)->where('public_id', $d['category'])->value('id');
                abort_unless($category, 404);
            }
            foreach ($d['barcodes'] as $barcode) {
                $qty = Decimal::input($barcode['quantity'], 3);
                if (bccomp($qty, '0', 3) <= 0) {
                    Decimal::fail('barcodes', 'Barcode multiplier must be positive.');
                }if (DB::table('product_barcodes')->where('tenant_id', $c->tenant->id)->where('barcode', $barcode['barcode'])->when($old, fn ($q) => $q->where('product_id', '!=', $old->id))->exists()) {
                    Decimal::fail('barcodes', 'A barcode already belongs to another product.');
                }
            }
            $row = $d;
            unset($row['barcodes'],$row['category']);
            $row['category_id'] = $category;
            $row['track_stock'] = $d['type'] !== 'service';
            $row['version'] = ($old?->version ?? 0) + 1;
            $row['updated_at'] = now();
            if ($old) {
                $id = $old->id;
                DB::table('products')->where('id', $id)->update($row);
            } else {
                $row += ['tenant_id' => $c->tenant->id, 'public_id' => (string) Str::uuid(), 'created_at' => now()];
                $id = DB::table('products')->insertGetId($row);
            }
            DB::table('product_barcodes')->where('tenant_id', $c->tenant->id)->where('product_id', $id)->delete();
            foreach ($d['barcodes'] as $b) {
                DB::table('product_barcodes')->insert(['tenant_id' => $c->tenant->id, 'product_id' => $id, 'barcode' => $b['barcode'], 'quantity' => Decimal::input($b['quantity'], 3)]);
            }
            if (! $old || bccomp($old->selling_price, $d['selling_price'], 2) !== 0) {
                DB::table('product_price_history')->insert(['tenant_id' => $c->tenant->id, 'product_id' => $id, 'user_id' => $c->user->id, 'old_price' => $old?->selling_price, 'new_price' => $d['selling_price'], 'created_at' => now()]);
            }$saved = DB::table('products')->find($id);
            Audit::record('product.saved', $c->tenant->id, $saved->public_id, ['version' => $saved->version]);

            return response()->json(['public_id' => $saved->public_id, 'version' => $saved->version], $old ? 200 : 201);
        }, 3);
    }
}
