<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\OperationScope;
use App\Services\Entitlements;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class InventoryIntelligence
{
    public const DEFAULTS = ['lookback_days' => 28, 'lead_time_days' => 7, 'safety_days' => 3, 'review_days' => 7, 'overstock_days' => 90, 'dead_stock_days' => 90, 'service_factor' => 1.65, 'alpha' => 0.3, 'method' => 'moving_average'];

    public function settings(int $tenant): array
    {
        $row = DB::table('intelligence_settings')->where('tenant_id', $tenant)->first();

        return ['parameters' => array_replace(self::DEFAULTS, $row ? json_decode($row->parameters, true) : []), 'version' => $row?->version ?? 0];
    }

    public function inventory(TenantContext $c, array $data): array
    {
        $scope = new OperationScope($c);
        $branch = $scope->branch($data['branch'], 'inventory.view');
        $warehouse = $scope->warehouse($data['warehouse'], $branch->id);
        $e = app(Entitlements::class);
        $e->authorize($c->tenant->id, 'builtin_intelligence');
        $e->authorize($c->tenant->id, 'inventory');
        $advanced = (bool) ($data['advanced'] ?? false);
        if ($advanced) {
            $e->authorize($c->tenant->id, 'forecasting');
        }
        $settings = $this->settings($c->tenant->id);
        $params = $settings['parameters'];
        // History access includes today; forecasts use only completed days.
        $days = min($params['lookback_days'], max(0, ($e->plan($c->tenant->id)->limits['history_days'] ?? 30) - 1));
        $end = CarbonImmutable::now($c->tenant->timezone)->startOfDay();
        $start = $end->subDays($days);
        $base = DB::table('products as p')->where('p.tenant_id', $c->tenant->id)->where('p.track_stock', true)->where('p.active', true)->whereNull('p.deleted_at');
        if (! empty($data['product'])) {
            $base->where('p.public_id', $data['product']);
        }
        $query = (clone $base)->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 'p.id')->on('b.tenant_id', '=', 'p.tenant_id')->where('b.warehouse_id', $warehouse->id));
        if (! $advanced) {
            $query->whereRaw('COALESCE(b.quantity,0)-COALESCE(b.reserved_quantity,0) <= p.reorder_level');
        }
        $query->orderBy('p.sku')->select('p.id', 'p.public_id', 'p.name', 'p.sku', 'p.type', 'p.unit', 'p.reorder_level', 'p.created_at')->selectRaw('COALESCE(b.quantity,0) as quantity, COALESCE(b.reserved_quantity,0) as reserved');
        $perPage = $data['per_page'] ?? 25;
        $number = $data['page'] ?? 1;
        // Counting forecast products needs no warehouse join; each balance is unique.
        $page = $advanced ? new LengthAwarePaginator($query->forPage($number, $perPage)->get(), (clone $base)->count(), $perPage, $number) : $query->paginate($perPage, ['*'], 'page', $number);
        $daily = [];
        if ($advanced && $page->count() && $days > 0) {
            // Construct only trusted CASE syntax. All timezone boundaries are bound parameters.
            $case = 'CASE';
            $bindings = [];
            for ($i = 0; $i < $days; $i++) {
                $case .= ' WHEN created_at >= ? AND created_at < ? THEN '.$i;
                $bindings[] = $start->addDays($i)->utc()->toDateTimeString();
                $bindings[] = $start->addDays($i + 1)->utc()->toDateTimeString();
            }$case .= ' END';
            $rows = DB::table('inventory_movements')->where('tenant_id', $c->tenant->id)->where('warehouse_id', $warehouse->id)->whereIn('product_id', $page->pluck('id'))->where('type', 'sale')->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())->select('product_id')->selectRaw($case.' as day_index', $bindings)->selectRaw('SUM(0-quantity) as sold_quantity')->groupBy('product_id', 'day_index')->get();
            foreach ($rows as $row) {
                $daily[$row->product_id][(int) $row->day_index] = (float) $row->sold_quantity;
            }
        }
        $items = [];
        foreach ($page as $product) {
            $available = bcsub((string) $product->quantity, (string) $product->reserved, 3);
            $low = bccomp($available, (string) $product->reorder_level, 3) <= 0;
            $item = ['product' => $product->public_id, 'name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unit, 'available' => bcadd($available, '0', 3), 'reorder_level' => bcadd((string) $product->reorder_level, '0', 3), 'low_stock' => $low];
            if ($advanced) {
                $created = CarbonImmutable::parse($product->created_at, 'UTC')->setTimezone($c->tenant->timezone)->startOfDay();
                $observed = $created->gte($end) ? 0 : (int) min($days, $created->diffInDays($end, true));
                $values = [];
                for ($i = $days - $observed; $i < $days; $i++) {
                    $values[] = $daily[$product->id][$i] ?? 0.0;
                }$item += $this->statistics($values, (float) $available, $product->type, $params, $start->addDays($days - $observed));
                $item['proposal_key'] = hash('sha256', json_encode([$c->tenant->public_id, $warehouse->public_id, $product->public_id, $item['available'], $item['recommended_quantity'], $settings['version'], $end->toDateString(), $values]));
            } else {
                $item['explanation'] = 'Available stock is at or below the product reorder level. No statistical forecast was requested.';
            }
            $items[] = $item;
        }
        if ($advanced) {
            $reviews = DB::table('reorder_reviews')->where('tenant_id', $c->tenant->id)->whereIn('proposal_key', array_column($items, 'proposal_key'))->get()->keyBy('proposal_key');
            foreach ($items as &$item) {
                $review = $reviews->get($item['proposal_key']);
                $item['review'] = $review ? ['decision' => $review->decision, 'note' => $review->note, 'created_at' => $review->created_at] : null;
            }unset($item);
        }

        return ['data' => $items, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'mode' => $advanced ? 'statistical_forecast' : 'low_stock', 'parameters' => $advanced ? $params : null, 'policy_version' => $settings['version'], 'from' => $start->toDateString(), 'until_exclusive' => $end->toDateString(), 'timezone' => $c->tenant->timezone, 'limitations' => ['Forecasts are estimates from recorded gross sale withdrawals, excluding returns/adjustments as demand; current availability already reflects stock movements.', 'Completed local days only. Missing sales and stockout-constrained demand cannot be inferred. No annual seasonality, supplier performance or expiry/batch data exists in this increment.', 'Recommendations and reviews do not create binding purchases or purchase orders.']];
    }

    public function statistics(array $daily, float $available, string $type, array $p, CarbonImmutable $start): array
    {
        $n = count($daily);
        $total = array_sum($daily);
        $mean = $n ? $total / $n : 0.0;
        $variance = $n ? array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $daily)) / $n : 0.0;
        $smooth = $daily[0] ?? 0.0;
        foreach (array_slice($daily, 1) as $value) {
            $smooth = $p['alpha'] * $value + (1 - $p['alpha']) * $smooth;
        }$rate = $p['method'] === 'exponential_smoothing' ? $smooth : $mean;
        $safety = max($rate * $p['safety_days'], $p['service_factor'] * sqrt($variance) * sqrt($p['lead_time_days']));
        $point = $rate * $p['lead_time_days'] + $safety;
        $suggest = max(0, $rate * ($p['lead_time_days'] + $p['review_days']) + $safety - $available);
        if (! $n) {
            $suggest = 0;
        }$suggest = $type === 'standard' ? ceil($suggest) : ceil($suggest * 1000) / 1000;
        $cover = $rate > 0 ? max(0, $available) / $rate : null;
        $week = [];
        foreach ($daily as $i => $v) {
            $day = $start->addDays($i)->format('l');
            $week[$day]['total'] = ($week[$day]['total'] ?? 0) + $v;
            $week[$day]['count'] = ($week[$day]['count'] ?? 0) + 1;
        }$pattern = null;
        if ($n >= 28 && $mean > 0) {
            $means = array_map(fn ($w) => $w['total'] / $w['count'], $week);
            arsort($means);
            $peak = array_key_first($means);
            if ($means[$peak] >= $mean * 1.5) {
                $pattern = ['peak_weekday' => $peak, 'relative_to_daily_mean' => round($means[$peak] / $mean, 2), 'method' => 'descriptive_weekday_means'];
            }
        }

        return ['observed_days' => $n, 'recorded_units' => number_format($total, 3, '.', ''), 'daily_velocity' => number_format($mean, 3, '.', ''), 'forecast_daily_units' => number_format($rate, 3, '.', ''), 'forecast_next_7_days' => number_format($rate * 7, 3, '.', ''), 'days_of_stock' => $cover === null ? null : round($cover, 1), 'safety_stock' => number_format($safety, 3, '.', ''), 'calculated_reorder_point' => number_format($point, 3, '.', ''), 'recommended_quantity' => number_format($suggest, 3, '.', ''), 'stockout_risk' => $rate > 0 && $available < $point, 'excess_stock' => $cover !== null && $cover > $p['overstock_days'], 'dead_stock' => $available > 0 && $total === 0.0 && $n >= $p['dead_stock_days'], 'weekday_pattern' => $pattern, 'method' => $p['method'], 'explanation' => $n === 0 ? 'No completed observation day exists. Collect sales history before using a forecast.' : sprintf('%d completed days; %.3f recorded units/day. Reorder point = %.3f demand during %d lead days + %.3f safety stock. Suggested quantity covers lead time plus %d review days after available stock. Safety = max(demand × safety days, service factor × daily deviation × √lead time).', $n, $mean, $rate * $p['lead_time_days'], $p['lead_time_days'], $safety, $p['review_days'])];
    }
}
