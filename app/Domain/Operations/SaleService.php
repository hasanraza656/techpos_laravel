<?php

namespace App\Domain\Operations;

use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SaleService
{
    public function __construct(private SaleCalculator $calculator, private StockLedger $stock) {}

    public function finalize(TenantContext $c, array $data): array
    {
        $scope = app(OperationScope::class);
        $branch = $scope->branch($data['branch'], 'sales.create');
        $warehouse = $scope->warehouse($data['warehouse'], $branch->id);
        app(Entitlements::class)->authorize($c->tenant->id, 'pos');
        if (isset($data['cashier'])) {
            abort_unless(hash_equals($c->user->public_id, $data['cashier']), 403, 'Sign in as the original cashier before synchronizing this transaction.');
        }
        $payload = $data;
        unset($payload['idempotency_key']);
        $canonical = function (array $v) use (&$canonical): array {
            if (! array_is_list($v)) {
                ksort($v);
            }foreach ($v as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $v;
        };
        $hash = hash('sha256', json_encode($canonical($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        try {
            return DB::transaction(function () use ($c, $data, $branch, $warehouse, $hash) {
                // Branch serialization is deliberately conservative for first correctness milestone.
                DB::table('branches')->where('id', $branch->id)->lockForUpdate()->first();
                $old = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->payload_hash, $hash) && $old->cashier_id === $c->user->id, 409, 'Idempotency key is bound to a different sale.');

                    return $this->document($c, $old->public_id, true);
                }
                $shift = DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('public_id', $data['shift'])->lockForUpdate()->first();
                abort_unless($shift, 404, 'Shift not found.');
                abort_if($shift->closed_at, 422, 'This shift is closed.');
                abort_unless($shift->user_id === $c->user->id, 403, 'Sales must use your own assigned shift.');
                if (($data['channel'] ?? 'online') === 'offline') {
                    app(Entitlements::class)->authorize($c->tenant->id, 'offline_basic');
                }
                if (! empty($data['device_identifier'])) {
                    $device = DB::table('devices')->where('tenant_id', $c->tenant->id)->where('user_id', $c->user->id)->where('device_identifier', $data['device_identifier'] ?? null)->whereNull('revoked_at')->lockForUpdate()->first();
                    abort_unless($device, 403, 'Register an active device before synchronizing offline sales.');
                    abort_if(DB::table('sales')->where('tenant_id', $c->tenant->id)->where('cashier_id', $c->user->id)->where('device_identifier', $data['device_identifier'])->where('local_sequence', $data['local_sequence'])->exists(), 409, 'Device sequence already belongs to a different sale.');
                }
                $customer = null;
                if (! empty($data['customer'])) {
                    $customer = DB::table('customers')->where('tenant_id', $c->tenant->id)->where('public_id', $data['customer'])->whereNull('deleted_at')->first();
                    abort_unless($customer, 404, 'Customer not found.');
                }
                $products = DB::table('products')->where('tenant_id', $c->tenant->id)->whereIn('public_id', array_column($data['lines'], 'product'))->where('active', true)->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
                $lines = [];
                foreach ($data['lines'] as $line) {
                    $p = $products->get($line['product']);
                    abort_unless($p, 422, 'A product is no longer available.');
                    $qty = Decimal::input($line['quantity'], 3);
                    if ($p->type === 'standard' && bccomp($qty, bcdiv($qty, '1', 0), 3) !== 0) {
                        Decimal::fail('quantity', 'Whole units are required for '.$p->name.'.');
                    }
                    $price = bcadd((string) ($customer?->price_tier === 'wholesale' && $p->wholesale_price !== null ? $p->wholesale_price : $p->selling_price), '0', 2);
                    if (isset($line['price_override'])) {
                        $c->authorize('products.change_price', $branch->id);
                        $price = Decimal::input($line['price_override']);
                    }
                    if (isset($line['expected_unit_price']) && bccomp(Decimal::input($line['expected_unit_price']), $price, 2) !== 0) {
                        abort(409, 'A product price changed. Review the sale before retrying.');
                    }
                    $discount = Decimal::input($line['discount'] ?? '0');
                    if (bccomp($discount, '0', 2) > 0) {
                        $c->authorize('sales.discount', $branch->id);
                    }
                    $lines[] = ['product' => $p, 'quantity' => $qty, 'unit_price' => $price, 'discount' => $discount, 'tax_rate' => bcadd((string) $p->tax_rate, '0', 4), 'tax_inclusive' => (bool) $p->tax_inclusive];
                }
                $cart = Decimal::input($data['cart_discount'] ?? '0');
                if (bccomp($cart, '0', 2) > 0) {
                    $c->authorize('sales.discount', $branch->id);
                }$calc = $this->calculator->calculate($lines, $cart);
                if (isset($data['expected_total']) && bccomp(Decimal::input($data['expected_total']), $calc['total'], 2) !== 0) {
                    abort(409, 'The authoritative total differs from the local sale. Review taxes, prices and discounts.');
                }
                $paid = '0.00';
                $cash = '0.00';
                foreach ($data['payments'] as $payment) {
                    $amount = Decimal::input($payment['amount']);
                    $zeroCashSale = bccomp($calc['total'], '0', 2) === 0 && count($data['payments']) === 1 && $payment['method'] === 'cash';
                    if (bccomp($amount, '0', 2) <= 0 && ! $zeroCashSale) {
                        Decimal::fail('payments', 'Payment amounts must be positive.');
                    }$paid = bcadd($paid, $amount, 2);
                    if ($payment['method'] === 'cash') {
                        $cash = bcadd($cash, $amount, 2);
                    } elseif (empty($payment['reference'])) {
                        Decimal::fail('payments', 'External payments need a terminal/bank reference.');
                    }
                }
                if (bccomp($paid, $calc['total'], 2) < 0) {
                    Decimal::fail('payments', 'Payment is less than the sale total.');
                }$change = bcsub($paid, $calc['total'], 2);
                if (bccomp($change, $cash, 2) > 0) {
                    Decimal::fail('payments', 'Change can only be returned from cash tender.');
                }
                if (bccomp($paid, '999999999999.99', 2) > 0) {
                    Decimal::fail('payments', 'Tender amount exceeds the supported limit.');
                }
                $uuid = $data['idempotency_key'];
                $cost = '0.0000';
                $lineRows = [];
                foreach ($calc['lines'] as $i => $line) {
                    $p = $line['product'];
                    $cogs = $p->track_stock ? bcsub('0', $this->stock->move($c->tenant->id, $branch->id, $warehouse->id, $p, bcsub('0', $line['quantity'], 3), 'sale', 'sale', $uuid, $c->user->id, 'Finalized sale'), 4) : '0.0000';
                    $cost = bcadd($cost, $cogs, 4);
                    $lineRows[] = ['tenant_id' => $c->tenant->id, 'product_id' => $p->id, 'position' => $i + 1, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'tax_rate' => $p->tax_rate, 'tax_inclusive' => $p->tax_inclusive, 'stock_tracked' => $p->track_stock, 'subtotal' => $line['subtotal'], 'line_discount' => $line['line_discount'], 'cart_discount' => $line['cart_discount'], 'tax' => $line['tax'], 'total' => $line['total'], 'cost_value' => $cogs];
                }
                // UUID-derived receipt is globally unambiguous and needs no unsafe MAX()+1 counter.
                // The date part is informational only and follows the business day (tenant timezone).
                $receipt = 'TP-'.now($c->tenant->timezone ?: 'UTC')->format('ymd').'-'.strtoupper(str_replace('-', '', $uuid));
                if (isset($data['client_occurred_at'])) {
                    $data['client_occurred_at'] = Carbon::parse($data['client_occurred_at'])->utc()->toDateTimeString();
                }
                $id = DB::table('sales')->insertGetId(['tenant_id' => $c->tenant->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'shift_id' => $shift->id, 'cashier_id' => $c->user->id, 'customer_id' => $customer?->id, 'public_id' => $uuid, 'idempotency_key' => $uuid, 'payload_hash' => $hash, 'receipt_number' => $receipt, 'currency' => $c->tenant->currency, 'channel' => $data['channel'] ?? 'online', 'device_identifier' => $data['device_identifier'] ?? null, 'local_sequence' => $data['local_sequence'] ?? null, 'payload_version' => 1, 'client_occurred_at' => $data['client_occurred_at'] ?? null, 'finalized_at' => now(), 'subtotal' => $calc['subtotal'], 'line_discount' => $calc['line_discount'], 'cart_discount' => $cart, 'tax_total' => $calc['tax_total'], 'total' => $calc['total'], 'amount_tendered' => $paid, 'change_due' => $change, 'cost_total' => $cost, 'note' => $data['note'] ?? null]);
                foreach ($lineRows as $row) {
                    DB::table('sale_lines')->insert($row + ['sale_id' => $id]);
                }
                foreach ($data['payments'] as $p) {
                    DB::table('sale_payments')->insert(['tenant_id' => $c->tenant->id, 'sale_id' => $id, 'method' => $p['method'], 'amount' => Decimal::input($p['amount']), 'reference' => $p['reference'] ?? null, 'verification' => $p['method'] === 'cash' ? 'cash_received' : 'externally_recorded', 'created_at' => now()]);
                }Audit::record('sale.finalized', $c->tenant->id, $uuid, ['total' => $calc['total'], 'branch' => $branch->public_id]);

                return $this->document($c, $uuid, true);
            }, 3);
        } catch (QueryException $e) {
            // Different branches may race the same tenant-wide UUID. The failed
            // transaction has rolled back all stock writes before we acknowledge.
            $old = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash) && $old->cashier_id === $c->user->id, 409, 'Idempotency key is bound to a different sale.');

                return $this->document($c, $old->public_id, true);
            }
            // A concurrent sale took this device sequence after our pre-check: same 409 as that check,
            // so sync reports this record for attention instead of failing the whole batch.
            if (isset($data['device_identifier'], $data['local_sequence']) && DB::table('sales')->where('tenant_id', $c->tenant->id)->where('cashier_id', $c->user->id)
                ->where('device_identifier', $data['device_identifier'])->where('local_sequence', $data['local_sequence'])->exists()) {
                abort(409, 'Device sequence already belongs to a different sale.');
            }
            throw $e;
        }
    }

    public function document(TenantContext $c, string $uuid, bool $ownWrite = false): array
    {
        $sale = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('public_id', $uuid)->first();
        abort_unless($sale, 404);
        if (! $ownWrite) {
            $c->authorize('sales.view', $sale->branch_id);
            $history = app(Entitlements::class)->plan($c->tenant->id)->limits['history_days'] ?? 30;
            abort_if(Carbon::parse($sale->finalized_at, 'UTC')->lt(now($c->tenant->timezone)->startOfDay()->subDays(max(0, $history - 1))->utc()), 403, 'This sale is outside your plan history access. Data is preserved.');
        }
        $canCost = $c->allows('products.view_cost', $sale->branch_id);
        $data = (array) $sale;
        unset($data['payload_hash']);
        if (! $canCost) {
            unset($data['cost_total']);
        }
        foreach (['subtotal', 'line_discount', 'cart_discount', 'tax_total', 'total', 'amount_tendered', 'change_due'] as $field) {
            $data[$field] = bcadd((string) $data[$field], '0', 2);
        }if ($canCost) {
            $data['cost_total'] = bcadd((string) $data['cost_total'], '0', 4);
        }
        $returned = DB::table('sale_return_lines')->where('tenant_id', $c->tenant->id)->whereIn('sale_line_id', DB::table('sale_lines')->where('sale_id', $sale->id)->select('id'))->get()->groupBy('sale_line_id');
        $data['lines'] = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.tenant_id', $c->tenant->id)->where('l.sale_id', $sale->id)->select('l.*', 'p.public_id as product')->orderBy('position')->get()->map(function ($l) use ($canCost, $returned) {
            $line = (array) $l;
            foreach (['subtotal', 'line_discount', 'cart_discount', 'tax', 'total', 'unit_price'] as $f) {
                $line[$f] = bcadd((string) $line[$f], '0', 2);
            }$line['quantity'] = bcadd((string) $line['quantity'], '0', 3);
            $line['returned_quantity'] = Decimal::sum(($returned->get($l->id) ?? collect())->pluck('quantity'), 3);
            $line['tax_rate'] = bcadd((string) $line['tax_rate'], '0', 4);
            if (! $canCost) {
                unset($line['cost_value']);
            } else {
                $line['cost_value'] = bcadd((string) $line['cost_value'], '0', 4);
            }

            return $line;
        });
        $data['payments'] = DB::table('sale_payments')->where('tenant_id', $c->tenant->id)->where('sale_id', $sale->id)->get()->map(function ($p) {
            $p->amount = bcadd((string) $p->amount, '0', 2);

            return $p;
        });
        $data['business_name'] = $c->tenant->name;
        $data['branch_name'] = DB::table('branches')->where('id', $sale->branch_id)->value('name');
        // Receipt-facing customer; historical sales still show it if the customer was archived later.
        $customer = $sale->customer_id ? DB::table('customers')->where('tenant_id', $c->tenant->id)->where('id', $sale->customer_id)->first(['public_id', 'name', 'phone']) : null;
        $data['customer'] = $customer ? ['public_id' => $customer->public_id, 'name' => $customer->name, 'phone' => $customer->phone] : null;

        return $data;
    }
}
