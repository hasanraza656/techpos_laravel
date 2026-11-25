<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\CustomerCredit;
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
    private const MONEY = '/^\d{1,10}(?:\.\d{1,2})?$/';

    private const SIGNED_MONEY = '/^-?\d{1,10}(?:\.\d{1,2})?$/';

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
        $page = $q->orderBy('name')->select('public_id', 'name', 'phone', 'email', 'price_tier', 'balance', 'credit_limit')->paginate(50);
        $page->getCollection()->transform(function ($row) {
            $row->balance = CustomerCredit::money($row->balance);
            $row->credit_limit = CustomerCredit::money($row->credit_limit);

            return $row;
        });

        return $page;
    }

    public function save(Request $r, TenantContext $c, OperationScope $scope, ?string $customer = null)
    {
        $d = $r->validate(['branch' => 'required|uuid', 'name' => 'required|string|max:150', 'phone' => 'nullable|string|max:40', 'email' => ['nullable', 'email', 'max:254', 'not_regex:/[\r\n]/'], 'address' => 'nullable|string|max:255', 'group_name' => 'nullable|string|max:80', 'price_tier' => 'required|in:retail,wholesale', // Required when creating; optional on update so a limit-only edit keeps the stored consent.
            'marketing_consent' => $customer ? 'sometimes|boolean' : 'required|boolean', 'notes' => 'nullable|string|max:3000', 'credit_limit' => ['sometimes', 'string', 'regex:'.self::MONEY]], ['credit_limit.regex' => 'Credit limit must be a non-negative amount with at most 2 decimal places.']);
        $b = $scope->branch($d['branch'], 'customers.manage');
        app(Entitlements::class)->authorize($c->tenant->id, 'customers');

        return DB::transaction(function () use ($d, $c, $b, $customer) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $old = $customer ? DB::table('customers')->where('tenant_id', $c->tenant->id)->where('public_id', $customer)->whereNull('deleted_at')->first() : null;
            if ($customer) {
                abort_unless($old, 404);
            }if (! empty($d['phone']) && DB::table('customers')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->where('phone', $d['phone'])->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists()) {
                Decimal::fail('phone', 'A customer with this phone already exists.');
            }$row = $d;
            unset($row['branch']);
            // The balance is ledger-owned and never accepted here; only the limit is editable.
            $limitChange = null;
            if (array_key_exists('credit_limit', $row)) {
                $row['credit_limit'] = Decimal::input($row['credit_limit']);
                $previous = CustomerCredit::money($old->credit_limit ?? '0');
                if (bccomp($previous, $row['credit_limit'], 2) !== 0) {
                    $c->authorize('customers.credit_manage', $b->id);
                    $limitChange = ['from' => $previous, 'to' => $row['credit_limit']];
                } else {
                    unset($row['credit_limit']);
                }
            }
            $row['updated_at'] = now();
            if ($old) {
                $id = $old->id;
                DB::table('customers')->where('id', $id)->update($row);
            } else {
                $row += ['tenant_id' => $c->tenant->id, 'public_id' => (string) Str::uuid(), 'created_at' => now()];
                $id = DB::table('customers')->insertGetId($row);
            }$saved = DB::table('customers')->find($id);
            Audit::record('customer.saved', $c->tenant->id, $saved->public_id);
            if ($limitChange) {
                Audit::record('customer.credit_limit_changed', $c->tenant->id, $saved->public_id, $limitChange);
            }

            return response()->json(['public_id' => $saved->public_id, 'name' => $saved->name, 'phone' => $saved->phone, 'email' => $saved->email, 'price_tier' => $saved->price_tier, 'balance' => CustomerCredit::money($saved->balance), 'credit_limit' => CustomerCredit::money($saved->credit_limit)], $old ? 200 : 201);
        }, 3);
    }

    public function ledger(Request $r, string $customer, TenantContext $c, CustomerCredit $credit)
    {
        $d = $r->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'page' => 'sometimes|integer|min:1']);

        return $credit->statement($c, $customer, $d);
    }

    public function payment(Request $r, string $customer, TenantContext $c, CustomerCredit $credit)
    {
        $d = $r->validate([
            'branch' => 'required|uuid', 'shift' => 'nullable|uuid', 'method' => 'required|in:cash,bank_transfer,external_card',
            'amount' => ['required', 'string', 'regex:'.self::MONEY], 'reference' => 'nullable|string|min:4|max:120', 'note' => 'nullable|string|max:500', 'idempotency_key' => 'required|uuid',
        ], ['amount.regex' => 'Amount must be a positive amount with at most 2 decimal places.', 'reference.min' => 'The reference must be at least 4 characters.']);

        return response()->json($credit->collect($c, $customer, $d), 201);
    }

    public function adjustment(Request $r, string $customer, TenantContext $c, CustomerCredit $credit)
    {
        $d = $r->validate([
            'type' => 'required|in:opening_balance,adjustment', 'amount' => ['required', 'string', 'regex:'.self::SIGNED_MONEY],
            'note' => 'required_if:type,adjustment|nullable|string|min:5|max:500', 'idempotency_key' => 'required|uuid',
        ], ['amount.regex' => 'Amount must be a decimal with at most 2 decimal places.', 'note.required_if' => 'Explain the adjustment in a note.', 'note.min' => 'The note must be at least 5 characters.']);

        return response()->json($credit->adjust($c, $customer, $d), 201);
    }
}
