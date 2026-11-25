<?php

namespace App\Domain\Operations;

use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

final class ReturnService
{
    public function finalize(TenantContext $c, string $saleUuid, array $data): array
    {
        $sale = DB::table('sales')->where('tenant_id', $c->tenant->id)->where('public_id', $saleUuid)->first();
        abort_unless($sale, 404);
        $c->authorize('sales.refund', $sale->branch_id);
        app(Entitlements::class)->authorize($c->tenant->id, 'returns');
        $hash = hash('sha256', json_encode([$saleUuid, $data], JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($c, $sale, $data, $hash) {
            DB::table('branches')->where('id', $sale->branch_id)->lockForUpdate()->first();
            $old = DB::table('sale_returns')->where('public_id', $data['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->tenant_id === $c->tenant->id && $old->user_id === $c->user->id && hash_equals($old->payload_hash, $hash), 409, 'Return key is bound to different details.');

                return $this->document($c, $old->public_id);
            }
            DB::table('sales')->where('id', $sale->id)->lockForUpdate()->first();
            abort_if(DB::table('sale_payments')->where('tenant_id', $c->tenant->id)->where('sale_id', $sale->id)->whereNotIn('method', ['cash', 'credit'])->exists(), 422, 'This workflow supports cash and customer-credit source sales. Externally recorded and card/bank refunds require a separate reconciliation workflow.');
            $shift = DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('branch_id', $sale->branch_id)->where('public_id', $data['shift'])->lockForUpdate()->first();
            abort_unless($shift, 404, 'Shift not found.');
            abort_if($shift->closed_at, 422, 'Open a shift before refunding cash.');
            abort_unless($shift->user_id === $c->user->id, 403, 'Refunds must use your own assigned shift.');
            // Khata still owed on this sale. The customer is locked before products, the same
            // order sales use, so returns and credit sales cannot deadlock on each other.
            $ledger = app(CustomerCredit::class);
            [$charged, $reversed] = $ledger->saleCredit($c->tenant->id, $sale->id);
            $open = bcsub($charged, $reversed, 2);
            $customer = $sale->customer_id && bccomp($open, '0', 2) > 0 ? $ledger->lock($c->tenant->id, $sale->customer_id) : null;
            $source = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.tenant_id', $c->tenant->id)->where('l.sale_id', $sale->id)->select('l.*', 'p.public_id as product')->get()->keyBy('product');
            $products = DB::table('products')->where('tenant_id', $c->tenant->id)->whereIn('public_id', array_column($data['lines'], 'product'))->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            $rows = [];
            $total = '0.00';
            $tax = '0.00';
            $cost = '0.0000';
            foreach ($data['lines'] as $line) {
                $original = $source->get($line['product']);
                abort_unless($original, 422, 'An item is not part of the original sale.');
                $qty = Decimal::input($line['quantity'], 3);
                if (bccomp($qty, '0', 3) <= 0) {
                    Decimal::fail('quantity', 'Return quantity must be positive.');
                }$product = $products->get($line['product']);
                abort_unless($product, 422, 'Product record is unavailable.');
                if ($product->type === 'standard' && bccomp($qty, bcdiv($qty, '1', 0), 3) !== 0) {
                    Decimal::fail('quantity', 'Whole units are required.');
                }
                $previous = DB::table('sale_return_lines')->where('tenant_id', $c->tenant->id)->where('sale_line_id', $original->id)->get();
                $used = Decimal::sum($previous->pluck('quantity'), 3);
                $remaining = bcsub($original->quantity, $used, 3);
                if (bccomp($qty, $remaining, 3) > 0) {
                    Decimal::fail('quantity', 'Return exceeds the unreturned quantity for '.$original->name.'.');
                }
                $allocation = function (string $field, int $precision) use ($qty, $remaining, $previous, $original, $used): string {
                    $allocated = Decimal::sum($previous->pluck($field), $precision);
                    $sourceField = $field === 'cost_allocated' ? 'cost_value' : $field;
                    $cumulative = bccomp($qty, $remaining, 3) === 0 ? $original->$sourceField : Decimal::round(bcdiv(bcmul($original->$sourceField, bcadd($used, $qty, 3), 12), $original->quantity, 12), $precision);

                    return bcsub($cumulative, $allocated, $precision);
                };
                $amount = $allocation('total', 2);
                $lineTax = $allocation('tax', 2);
                $lineCost = $allocation('cost_allocated', 4);
                $allocatedCost = $lineCost;
                $restocked = (bool) $line['restock'] && (bool) $original->stock_tracked;
                if ($restocked) {
                    app(StockLedger::class)->move($c->tenant->id, $sale->branch_id, $sale->warehouse_id, $product, $qty, 'return', 'sale_return', $data['idempotency_key'], $c->user->id, $data['reason'], null, $lineCost);
                    $cost = bcadd($cost, $lineCost, 4);
                } else {
                    $lineCost = '0.0000';
                }
                $rows[] = ['tenant_id' => $c->tenant->id, 'sale_line_id' => $original->id, 'quantity' => $qty, 'total' => $amount, 'tax' => $lineTax, 'cost_allocated' => $allocatedCost, 'cost_reversed' => $lineCost, 'restocked' => $restocked];
                $total = bcadd($total, $amount, 2);
                $tax = bcadd($tax, $lineTax, 2);
            }
            // Credit-first refund: reduce the khata by what this sale still owes on credit, the
            // remainder is cash. Capped at the current balance so a customer who already paid
            // the khata back is refunded in cash instead of being given a negative balance.
            $creditPart = $customer ? Decimal::min(Decimal::min($total, $open), CustomerCredit::money($customer->balance)) : '0.00';
            // Never alter the source sale/tenders/COGS. A separate cash refund reduces this shift's expected cash.
            $id = DB::table('sale_returns')->insertGetId(['public_id' => $data['idempotency_key'], 'tenant_id' => $c->tenant->id, 'branch_id' => $sale->branch_id, 'sale_id' => $sale->id, 'warehouse_id' => $sale->warehouse_id, 'shift_id' => $shift->id, 'user_id' => $c->user->id, 'payload_hash' => $hash, 'receipt_number' => 'CR-'.strtoupper(str_replace('-', '', $data['idempotency_key'])), 'total' => $total, 'tax_total' => $tax, 'cost_reversed' => $cost, 'credit_amount' => $creditPart, 'reason' => $data['reason'], 'created_at' => now()]);
            foreach ($rows as $row) {
                DB::table('sale_return_lines')->insert($row + ['return_id' => $id]);
            }
            if ($customer && bccomp($creditPart, '0', 2) > 0) {
                $ledger->append($c, $customer, 'credit_return', bcsub('0', $creditPart, 2), $data['idempotency_key'], $hash, ['branch_id' => $sale->branch_id, 'shift_id' => $shift->id, 'sale_id' => $sale->id, 'return_id' => $id, 'note' => $data['reason']]);
            }
            Audit::record('sale.returned', $c->tenant->id, $data['idempotency_key'], ['sale' => $sale->public_id, 'total' => $total, 'credit_amount' => $creditPart]);

            return $this->document($c, $data['idempotency_key']);
        }, 3);
    }

    public function document(TenantContext $c, string $uuid): array
    {
        $r = DB::table('sale_returns')->where('tenant_id', $c->tenant->id)->where('public_id', $uuid)->first();
        abort_unless($r, 404);
        $c->authorize('sales.refund', $r->branch_id);
        $data = (array) $r;
        unset($data['payload_hash']);
        foreach (['total', 'tax_total', 'credit_amount'] as $f) {
            $data[$f] = bcadd((string) $data[$f], '0', 2);
        }
        $data['cash_amount'] = bcsub($data['total'], $data['credit_amount'], 2);$data['cost_reversed'] = bcadd((string) $data['cost_reversed'], '0', 4);
        $data['lines'] = DB::table('sale_return_lines as r')->join('sale_lines as l', 'l.id', '=', 'r.sale_line_id')->where('r.return_id', $r->id)->select('l.name', 'r.quantity', 'r.total', 'r.tax', 'r.cost_reversed', 'r.restocked')->get();
        if (! $c->allows('products.view_cost', $r->branch_id)) {
            unset($data['cost_reversed']);
            foreach ($data['lines'] as $line) {
                unset($line->cost_reversed);
            }
        }

        return $data;
    }
}
