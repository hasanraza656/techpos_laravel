<?php

namespace App\Domain\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockLedger
{
    // Call inside a transaction; callers lock the branch and sorted products first.
    public function move(int $tenant, int $branch, int $warehouse, object $product, string $quantity, string $type, string $sourceType, string $sourceId, int $user, string $reason, ?string $inboundUnitCost = null, ?string $inboundValue = null): string
    {
        if (! $product->track_stock) {
            Decimal::fail('product', 'A non-stock service cannot have inventory movements.');
        }
        $delta = Decimal::input($quantity, 3, true);
        if (bccomp($delta, '0', 3) === 0) {
            Decimal::fail('quantity', 'Movement quantity must not be zero.');
        }
        DB::table('stock_balances')->insertOrIgnore(['tenant_id' => $tenant, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id, 'quantity' => '0.000', 'reserved_quantity' => '0.000', 'cost_value' => '0.0000', 'created_at' => now(), 'updated_at' => now()]);
        $balance = DB::table('stock_balances')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse)->where('product_id', $product->id)->lockForUpdate()->first();
        $after = bcadd($balance->quantity, $delta, 3);
        if (bccomp($after, '9999999999999.999', 3) > 0) {
            Decimal::fail('stock', 'Stock exceeds the supported quantity limit.');
        }
        if (bccomp($after, $balance->reserved_quantity, 3) < 0) {
            Decimal::fail('stock', 'Insufficient available stock for '.$product->name.'.');
        }
        if (bccomp($delta, '0', 3) > 0) {
            $unit = Decimal::input($inboundUnitCost ?? (string) $product->cost_price, 4);
            $value = $inboundValue === null ? Decimal::multiply($delta, $unit, 4) : bcadd($inboundValue, '0', 4);
        } else {
            $out = bcsub('0', $delta, 3);
            $cost = bccomp($out, $balance->quantity, 3) === 0 ? $balance->cost_value : Decimal::round(bcdiv(bcmul($balance->cost_value, $out, 12), $balance->quantity, 12), 4);
            $value = bcsub('0', $cost, 4);
        }
        $nextCost = bcadd((string) $balance->cost_value, $value, 4);
        if (bccomp($nextCost, '9999999999999999.9999', 4) > 0) {
            Decimal::fail('cost', 'Stock value exceeds the supported limit.');
        }
        DB::table('inventory_movements')->insert(['public_id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id, 'user_id' => $user, 'type' => $type, 'quantity' => $delta, 'reserved_delta' => '0.000', 'cost_value' => $value, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => $reason, 'created_at' => now()]);
        DB::table('stock_balances')->where('id', $balance->id)->update(['quantity' => $after, 'cost_value' => bcadd($balance->cost_value, $value, 4), 'updated_at' => now()]);

        return $value;
    }
}
