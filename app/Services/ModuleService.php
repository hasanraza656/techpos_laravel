<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ModuleService
{
    public function listing(int $tenantId): array
    {
        $e = app(Entitlements::class);
        $states = $e->states($tenantId);

        return DB::table('features')->get()->map(function ($f) use ($states) {
            $deps = json_decode($f->dependencies, true);
            $included = $states[$f->key]['included'];
            $enabled = $states[$f->key]['enabled'];
            $missing = array_values(array_filter($deps, fn ($d) => ! ($states[$d]['enabled'] ?? false)));
            $ready = ['pos', 'inventory', 'customers', 'receipts', 'daily_summary', 'builtin_intelligence'];
            $partial = ['offline_basic', 'reports', 'analytics', 'forecasting', 'returns', 'promotions', 'warehouses', 'advanced_permissions', 'bulk_data'];
            $availability = in_array($f->key, $ready, true) ? 'available' : (in_array($f->key, $partial, true) ? 'partial' : 'planned');

            return ['key' => $f->key, 'name' => $f->name, 'category' => $f->category, 'description' => $f->description, 'dependencies' => $deps, 'included' => $included, 'enabled' => $enabled, 'missing_dependencies' => $missing, 'requires_configuration' => (bool) $f->requires_configuration, 'availability' => $availability];
        })->all();
    }

    public function set(int $tenantId, string $key, bool $enabled): void
    {
        DB::transaction(function () use ($tenantId, $key, $enabled) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $e = app(Entitlements::class);
            $f = DB::table('features')->where('key', $key)->first();
            abort_unless($f, 404);
            if ($enabled && ! $e->included($tenantId, $key)) {
                throw ValidationException::withMessages(['module' => 'This module requires a plan upgrade.']);
            }
            if ($enabled) {
                foreach (json_decode($f->dependencies, true) as $dep) {
                    if (! $e->enabled($tenantId, $dep)) {
                        throw ValidationException::withMessages(['module' => "Enable $dep first."]);
                    }
                }
            }
            if (! $enabled) {
                foreach ($this->listing($tenantId) as $other) {
                    if ($other['enabled'] && in_array($key, $other['dependencies'])) {
                        throw ValidationException::withMessages(['module' => 'Disable '.$other['name'].' first. Historical records will be preserved.']);
                    }
                }
            }
            DB::table('tenant_feature_overrides')->updateOrInsert(['tenant_id' => $tenantId, 'feature_id' => $f->id], ['enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('module.updated', $tenantId, $key, ['enabled' => $enabled]);
        });
    }
}
