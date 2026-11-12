<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\Decimal;
use App\Domain\Operations\OperationScope;
use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index(Request $r, TenantContext $c, OperationScope $scope)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'q' => 'nullable|string|max:150']);
        $b = $scope->branch($d['branch'], 'sales.create');
        app(Entitlements::class)->authorize($c->tenant->id, 'customers');
        $q = DB::table('customers')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at');
        if (! empty($d['q'])) {
            $term = addcslashes($d['q'], '%_\\');
            $q->where(fn ($s) => $s->where('name', 'like', "%$term%")->orWhere('phone', 'like', "%$term%")->orWhere('email', 'like', "%$term%"));
        }

        return $q->orderBy('name')->select('public_id', 'name', 'phone', 'email', 'price_tier')->paginate(50);
    }

    public function save(Request $r, TenantContext $c, OperationScope $scope, ?string $customer = null)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'name' => 'required|string|max:150', 'phone' => 'nullable|string|max:40', 'email' => ['nullable', 'email', 'max:254', 'not_regex:/[\r\n]/'], 'address' => 'nullable|string|max:255', 'group_name' => 'nullable|string|max:80', 'price_tier' => 'required|in:retail,wholesale', 'marketing_consent' => 'required|boolean', 'notes' => 'nullable|string|max:3000']);
        $b = $scope->branch($d['branch'], 'customers.manage');
        app(Entitlements::class)->authorize($c->tenant->id, 'customers');

        return DB::transaction(function () use ($d, $c, $customer) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $old = $customer ? DB::table('customers')->where('tenant_id', $c->tenant->id)->where('public_id', $customer)->whereNull('deleted_at')->first() : null;
            if ($customer) {
                abort_unless($old, 404);
            }if (! empty($d['phone']) && DB::table('customers')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->where('phone', $d['phone'])->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists()) {
                Decimal::fail('phone', 'A customer with this phone already exists.');
            }$row = $d;
            unset($row['branch']);
            $row['updated_at'] = now();
            if ($old) {
                $id = $old->id;
                DB::table('customers')->where('id', $id)->update($row);
            } else {
                $row += ['tenant_id' => $c->tenant->id, 'public_id' => (string) Str::uuid(), 'created_at' => now()];
                $id = DB::table('customers')->insertGetId($row);
            }$saved = DB::table('customers')->find($id);
            Audit::record('customer.saved', $c->tenant->id, $saved->public_id);

            return response()->json(['public_id' => $saved->public_id, 'name' => $saved->name, 'phone' => $saved->phone, 'email' => $saved->email, 'price_tier' => $saved->price_tier], $old ? 200 : 201);
        }, 3);
    }
}
