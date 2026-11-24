<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\Decimal;
use App\Domain\Operations\OperationScope;
use App\Domain\Operations\RegisterService;
use App\Domain\Operations\ReportService;
use App\Domain\Operations\ReturnService;
use App\Domain\Operations\SaleService;
use App\Domain\Operations\StockLedger;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinalizeSaleRequest;
use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OperationsController extends Controller
{
    public function bootstrap(TenantContext $c, RegisterService $register)
    {
        $permissions = DB::table('permissions')->pluck('key');
        // Load the member's grants once (same semantics as TenantContext::allows) instead of
        // one query per branch x permission.
        $grants = $c->owner ? collect() : DB::table('user_role_assignments as a')->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.tenant_id', $c->tenant->id)->where('a.user_id', $c->user->id)->select('a.branch_id', 'p.key')->get();
        $allowed = fn (int $branchId) => $c->owner ? $permissions : $grants->filter(fn ($g) => $g->branch_id === null || (int) $g->branch_id === $branchId)->pluck('key')->unique();
        $branches = DB::table('branches')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->get()->map(function ($b) use ($allowed) {
            $b->granted = $allowed($b->id);

            return $b;
        })->filter(fn ($b) => $b->granted->intersect(['products.view', 'sales.create', 'reports.view', 'inventory.view'])->isNotEmpty())->values();
        $ids = $branches->pluck('id');
        $data = $branches->map(fn ($b) => ['public_id' => $b->public_id, 'name' => $b->name, 'permissions' => $permissions->filter(fn ($key) => $b->granted->contains($key))->values()]);
        $map = $branches->keyBy('id');
        $warehouses = DB::table('warehouses')->where('tenant_id', $c->tenant->id)->whereIn('branch_id', $ids)->get()->map(fn ($w) => ['public_id' => $w->public_id, 'name' => $w->name, 'branch' => $map[$w->branch_id]->public_id]);
        $registers = DB::table('registers')->where('tenant_id', $c->tenant->id)->whereIn('branch_id', $ids)->where('active', true)->get()->map(fn ($r) => ['public_id' => $r->public_id, 'name' => $r->name, 'branch' => $map[$r->branch_id]->public_id]);
        $shift = DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('user_id', $c->user->id)->whereNull('closed_at')->first();
        // Resolve module states once; each Entitlements::enabled() call re-reads the plan catalog (6 queries).
        $modules = app(Entitlements::class)->states($c->tenant->id);

        return ['branches' => $data, 'warehouses' => $warehouses, 'registers' => $registers, 'shift' => $shift ? ['public_id' => $shift->public_id, 'branch' => $map->get($shift->branch_id)?->public_id, 'register' => DB::table('registers')->where('id', $shift->register_id)->value('public_id'), 'opening_float' => $shift->opening_float, 'expected_cash' => $register->expected($c->tenant->id, $shift->id), 'opened_at' => $shift->opened_at] : null, 'currency' => $c->tenant->currency, 'timezone' => $c->tenant->timezone, 'modules' => ['pos' => $modules['pos']['enabled'] ?? false, 'inventory' => $modules['inventory']['enabled'] ?? false, 'offline_basic' => $modules['offline_basic']['enabled'] ?? false]];
    }

    public function open(Request $r, TenantContext $c, OperationScope $scope, RegisterService $s)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'register' => 'required|uuid', 'opening_float' => 'required|string', 'idempotency_key' => 'required|uuid']);
        $b = $scope->branch($d['branch'], 'sales.create');
        app(Entitlements::class)->authorize($c->tenant->id, 'pos');

        return response()->json($s->open($c, $b, $d), 201);
    }

    public function close(Request $r, string $shift, TenantContext $c, RegisterService $s)
    {
        $d = $r->validate(['actual_cash' => 'required|string', 'idempotency_key' => 'required|uuid', 'note' => 'nullable|string|max:1000']);

        return $s->close($c, $shift, $d);
    }

    public function cash(Request $r, string $shift, TenantContext $c, RegisterService $s)
    {
        $d = $r->validate(['amount' => 'required|string', 'idempotency_key' => 'required|uuid', 'type' => 'required|in:cash_in,cash_out,cash_expense', 'reason' => 'required|string|min:3|max:255']);

        return response()->json($s->cashEvent($c, $shift, $d), 201);
    }

    public function sale(FinalizeSaleRequest $r, TenantContext $c, SaleService $s)
    {
        return response()->json($s->finalize($c, $r->validated()), 201);
    }

    public function document(string $sale, TenantContext $c, SaleService $s)
    {
        return $s->document($c, $sale);
    }

    public function refund(Request $r, string $sale, TenantContext $c, ReturnService $s)
    {
        $d = $r->validate(['idempotency_key' => 'required|uuid', 'shift' => 'required|uuid', 'reason' => 'required|string|min:3|max:255', 'lines' => 'required|array|min:1|max:200', 'lines.*' => 'required|array:product,quantity,restock', 'lines.*.product' => 'required|uuid|distinct', 'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,3})?$/'], 'lines.*.restock' => 'required|boolean']);

        return response()->json($s->finalize($c, $sale, $d), 201);
    }

    public function returnDocument(string $return, TenantContext $c, ReturnService $s)
    {
        return $s->document($c, $return);
    }

    public function sales(Request $r, TenantContext $c, OperationScope $scope, ReportService $reports)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'q' => 'nullable|string|max:60']);
        $b = $scope->branch($d['branch'], 'sales.view');
        [$from,$until] = $reports->range($c, $d);
        $fields = ['public_id', 'receipt_number', 'finalized_at', 'total', 'tax_total', 'currency'];
        $q = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('branch_id', $b->id)->where('finalized_at', '>=', $from)->where('finalized_at', '<', $until);
        if (! empty($d['q'])) {
            $q->where('receipt_number', 'like', '%'.addcslashes($d['q'], '%_\\').'%');
        }
        $page = $q->orderByDesc('id')->select($fields)->paginate(50);
        $page->getCollection()->transform(function ($s) {
            foreach (['total', 'tax_total'] as $f) {
                $s->$f = bcadd((string) $s->$f, '0', 2);
            }

            return $s;
        });

        return $page;
    }

    public function summary(Request $r, TenantContext $c, OperationScope $scope, ReportService $reports)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d']);
        $b = $scope->branch($d['branch'], 'reports.view');

        return $reports->summary($c, $b, $d);
    }

    public function sync(Request $r, TenantContext $c, SaleService $s)
    {
        $batch = $r->validate(['records' => 'required|array|min:1|max:25', 'records.*' => 'required|array']);
        $results = [];
        foreach ($batch['records'] as $index => $record) {
            try {
                $validated = Validator::make($record, (new FinalizeSaleRequest)->rules())->validate();
                $results[] = ['index' => $index, 'id' => $validated['idempotency_key'], 'status' => 'accepted', 'sale' => $s->finalize($c, $validated)];
            } catch (ValidationException $e) {
                $results[] = ['index' => $index, 'status' => 'attention', 'code' => 422, 'errors' => $e->errors()];
            } catch (HttpExceptionInterface $e) {
                $results[] = ['index' => $index, 'status' => 'attention', 'code' => $e->getStatusCode(), 'message' => $e->getMessage()];
            } catch (AuthorizationException $e) {
                $results[] = ['index' => $index, 'status' => 'attention', 'code' => 403, 'message' => 'You are not authorized to perform this action.'];
            }
        }

        return ['results' => $results];
    }

    public function stock(Request $r, TenantContext $c, OperationScope $scope)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'warehouse' => 'required|uuid', 'product' => 'sometimes|uuid']);
        $b = $scope->branch($d['branch'], 'inventory.view');
        $w = $scope->warehouse($d['warehouse'], $b->id);
        $q = DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.tenant_id', $c->tenant->id)->where('m.warehouse_id', $w->id);
        if (isset($d['product'])) {
            $q->where('p.public_id', $d['product']);
        }$fields = ['m.public_id', 'm.type', 'm.quantity', 'm.reason', 'm.source_type', 'm.source_id', 'm.created_at', 'p.public_id as product', 'p.name', 'p.sku'];
        if ($c->allows('products.view_cost', $b->id)) {
            $fields[] = 'm.cost_value';
        }

        return $q->select($fields)->orderByDesc('m.id')->paginate(50);
    }

    public function adjustment(Request $r, TenantContext $c, OperationScope $scope, StockLedger $ledger)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'warehouse' => 'required|uuid', 'product' => 'required|uuid', 'quantity' => 'required|string', 'unit_cost' => 'nullable|string', 'type' => 'required|in:opening_stock,adjustment,damage,expiry', 'reason' => 'required|string|min:3|max:255', 'idempotency_key' => 'required|uuid']);
        $b = $scope->branch($d['branch'], 'inventory.adjust');
        $w = $scope->warehouse($d['warehouse'], $b->id);
        app(Entitlements::class)->authorize($c->tenant->id, 'inventory');
        $qty = Decimal::input($d['quantity'], 3, true);
        $cost = isset($d['unit_cost']) ? Decimal::input($d['unit_cost'], 4) : null;
        if ($d['type'] === 'opening_stock' && bccomp($qty, '0', 3) <= 0) {
            Decimal::fail('quantity', 'Opening stock must be positive.');
        }if (in_array($d['type'], ['damage', 'expiry']) && bccomp($qty, '0', 3) >= 0) {
            Decimal::fail('quantity', 'Damage and expiry must remove stock.');
        }if (bccomp($qty, '0', 3) > 0 && $cost === null) {
            Decimal::fail('unit_cost', 'Positive stock receipts require their actual unit cost.');
        }

        return DB::transaction(function () use ($d, $c, $b, $w, $ledger, $qty, $cost) {
            DB::table('branches')->where('id', $b->id)->lockForUpdate()->first();
            $p = DB::table('products')->where('tenant_id', $c->tenant->id)->where('public_id', $d['product'])->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($p, 404);
            if ($p->type === 'standard' && bccomp($qty, bcdiv($qty, '1', 0), 3) !== 0) {
                Decimal::fail('quantity', 'This product requires whole units.');
            }
            $old = DB::table('inventory_movements')->where('tenant_id', $c->tenant->id)->where('source_type', 'manual_adjustment')->where('source_id', $d['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->warehouse_id === $w->id && $old->product_id === $p->id && $old->type === $d['type'] && bccomp($old->quantity, $qty, 3) === 0 && $old->reason === $d['reason'] && ($cost === null || bccomp($old->cost_value, Decimal::multiply($qty, $cost, 4), 4) === 0), 409, 'Adjustment key was used for different details.');

                return response()->json(['public_id' => $old->public_id, 'replayed' => true]);
            }
            $ledger->move($c->tenant->id, $b->id, $w->id, $p, $qty, $d['type'], 'manual_adjustment', $d['idempotency_key'], $c->user->id, $d['reason'], $cost);
            Audit::record('inventory.adjusted', $c->tenant->id, $d['idempotency_key'], ['product' => $p->public_id, 'quantity' => $qty]);

            return response()->json(['message' => 'Inventory movement recorded.'], 201);
        }, 3);
    }

    public function hold(Request $r, TenantContext $c, OperationScope $scope)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'name' => 'required|string|max:100', 'payload' => 'required|array:lines,customer,cart_discount', 'payload.lines' => 'required|array|min:1|max:200', 'payload.lines.*' => 'array:product,quantity,discount', 'payload.lines.*.product' => 'required|uuid|distinct', 'payload.lines.*.quantity' => 'required|string', 'payload.lines.*.discount' => 'sometimes|string', 'payload.customer' => 'nullable|uuid', 'payload.cart_discount' => 'sometimes|string']);
        $b = $scope->branch($d['branch'], 'sales.create');
        app(Entitlements::class)->authorize($c->tenant->id, 'pos');
        foreach ($d['payload']['lines'] as $l) {
            if (bccomp(Decimal::input($l['quantity'], 3), '0', 3) <= 0) {
                Decimal::fail('quantity', 'Held quantities must be positive.');
            }
            Decimal::input($l['discount'] ?? '0'); // unsigned decimal; negatives are rejected
            abort_unless(DB::table('products')->where('tenant_id', $c->tenant->id)->where('public_id', $l['product'])->exists(), 422, 'A held product does not belong to this business.');
        }if (! empty($d['payload']['customer'])) {
            abort_unless(DB::table('customers')->where('tenant_id', $c->tenant->id)->where('public_id', $d['payload']['customer'])->whereNull('deleted_at')->exists(), 422);
        }
        // The held list returns at most 100 carts; refuse more instead of silently hiding older ones.
        abort_if(DB::table('held_sales')->where('tenant_id', $c->tenant->id)->where('branch_id', $b->id)->where('user_id', $c->user->id)->count() >= 100, 422, 'You have 100 held carts. Resume or discard some before holding another.');
        $uuid = (string) Str::uuid();
        DB::table('held_sales')->insert(['tenant_id' => $c->tenant->id, 'branch_id' => $b->id, 'user_id' => $c->user->id, 'public_id' => $uuid, 'name' => $d['name'], 'payload' => json_encode($d['payload']), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['public_id' => $uuid], 201);
    }

    public function held(Request $r, TenantContext $c, OperationScope $scope)
    {
        $d = $r->validate(['branch' => 'required|uuid']);
        $b = $scope->branch($d['branch'], 'sales.create');

        return DB::table('held_sales')->where('tenant_id', $c->tenant->id)->where('branch_id', $b->id)->where('user_id', $c->user->id)->orderByDesc('id')->limit(100)->select('public_id', 'name', 'payload', 'created_at')->get();
    }

    public function discard(string $held, TenantContext $c)
    {
        $h = DB::table('held_sales')->where('tenant_id', $c->tenant->id)->where('user_id', $c->user->id)->where('public_id', $held)->first();
        abort_unless($h, 404);
        $c->authorize('sales.create', $h->branch_id);
        DB::table('held_sales')->where('id', $h->id)->delete();

        return response()->noContent();
    }
}
