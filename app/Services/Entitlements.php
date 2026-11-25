<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Entitlements
{
    public function subscription(int $tenantId): ?object
    {
        return DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
    }

    public function plan(int $tenantId): Plan
    {
        $s = $this->subscription($tenantId);
        if ($s && in_array($s->status, ['active', 'grace']) && (! $s->ends_at || now()->lt($s->ends_at) || ($s->status === 'grace' && $s->grace_ends_at && now()->lt($s->grace_ends_at)))) {
            return Plan::with('prices')->findOrFail($s->plan_id);
        }

        return Plan::with('prices')->where('key', 'free')->firstOrFail();
    }

    public function included(int $tenantId, string $key): bool
    {
        return DB::table('feature_entitlements as e')->join('features as f', 'f.id', '=', 'e.feature_id')->where('e.plan_id', $this->plan($tenantId)->id)->where('f.key', $key)->exists();
    }

    public function enabled(int $tenantId, string $key): bool
    {
        return $this->states($tenantId)[$key]['enabled'] ?? false;
    }

    /** Fresh, bounded catalog reads; no persistent permission/plan cache. */
    public function states(int $tenantId): array
    {
        $features = DB::table('features')->get()->keyBy('key');
        $included = DB::table('feature_entitlements')->where('plan_id', $this->plan($tenantId)->id)->pluck('feature_id')->flip();
        $overrides = DB::table('tenant_feature_overrides')->where('tenant_id', $tenantId)->pluck('enabled', 'feature_id');
        $core = ['pos', 'inventory', 'customers', 'receipts', 'offline_basic', 'daily_summary', 'builtin_intelligence', 'customer_credit'];
        $resolved = [];
        $visiting = [];
        $resolve = function (string $key) use (&$resolve, &$resolved, &$visiting, $features, $included, $overrides, $core): bool {
            if (array_key_exists($key, $resolved)) {
                return $resolved[$key];
            }
            if (isset($visiting[$key]) || ! $features->has($key)) {
                return false;
            }
            $f = $features[$key];
            $requested = $overrides->has($f->id) ? (bool) $overrides[$f->id] : in_array($key, $core, true);
            if (! $included->has($f->id) || ! $requested) {
                return $resolved[$key] = false;
            }
            $visiting[$key] = true;
            foreach (json_decode($f->dependencies, true) ?? [] as $dep) {
                if (! $resolve($dep)) {
                    unset($visiting[$key]);

                    return $resolved[$key] = false;
                }
            }
            unset($visiting[$key]);

            return $resolved[$key] = true;
        };
        $states = [];
        foreach ($features as $key => $feature) {
            $states[$key] = ['included' => $included->has($feature->id), 'enabled' => $resolve($key)];
        }

return $states;
    }

    public function authorize(int $tenantId, string $key): void
    {
        abort_unless($this->enabled($tenantId, $key), 403, 'This module is not enabled or included in your plan.');
    }

    public function assertLimit(int $tenantId, string $key, int $current, int $additional = 1): void
    {
        $limit = $this->plan($tenantId)->limits[$key] ?? 0;
        if ($current + $additional > $limit) {
            throw ValidationException::withMessages(['limit' => "Your plan allows $limit $key. Existing data is preserved; upgrade to add more."]);
        }
    }
}
