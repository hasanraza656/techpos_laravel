<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LabelService;
use App\Services\ModuleService;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfigurationController extends Controller
{
    public function industries()
    {
        return DB::table('industries')->get()->map(function ($i) {
            $i->template = json_decode($i->template, true);

            return $i;
        });
    }

    public function onboarding(TenantContext $c)
    {
        return DB::table('onboarding_progress')->where('tenant_id', $c->tenant->id)->first();
    }

    public function saveOnboarding(Request $r, TenantContext $c)
    {
        $c->authorize('onboarding.manage');
        $d = $r->validate(['step' => 'required|integer|min:1|max:5', 'revision' => 'sometimes|integer|min:1', 'deferred' => 'sometimes|boolean', 'industry_key' => 'sometimes|nullable|string|exists:industries,key', 'business_model' => 'sometimes|nullable|in:retail,wholesale,both,services,invoicing', 'operation' => 'sometimes|nullable|in:physical,online,both', 'locations' => 'sometimes|nullable|in:one,multiple,unsure', 'name' => 'sometimes|string|max:150', 'phone' => 'sometimes|nullable|string|max:30', 'address' => 'sometimes|nullable|string|max:255', 'city' => 'sometimes|nullable|string|max:100', 'country' => 'sometimes|string|size:2', 'currency' => 'sometimes|string|in:PKR,USD,GBP,EUR,AED,SAR,INR', 'timezone' => 'sometimes|timezone', 'locale' => ['sometimes', Rule::in(LabelService::LOCALES)], 'completed' => 'sometimes|boolean']);

        return DB::transaction(function () use ($d, $c) {
            $tenant = Tenant::whereKey($c->tenant->id)->lockForUpdate()->firstOrFail();
            $progress = DB::table('onboarding_progress')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            abort_if(isset($d['revision']) && (int) $d['revision'] !== (int) $progress->revision, 409, 'Your setup changed in another tab. Review the saved answers before continuing.');
            $revision = $progress->revision + 1;
            $deferred = $d['deferred'] ?? false;
            unset($d['revision'], $d['deferred']);
            $data = array_replace(json_decode($progress->data, true), $d);
            if (($d['completed'] ?? false) && empty($data['industry_key'])) {
                throw ValidationException::withMessages(['industry_key' => 'Choose an industry to personalize your setup.']);
            }
            $changes = array_intersect_key($d, array_flip(['name', 'country', 'currency', 'timezone', 'locale']));
            if (isset($changes['currency']) && $changes['currency'] !== $tenant->currency) {
                // Serialize with stock/sale writers; new branch creation also takes the tenant lock.
                DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('id')->lockForUpdate()->get();
                // Shift creation locks its register, so protect the first cash record too.
                DB::table('registers')->where('tenant_id', $tenant->id)->orderBy('id')->lockForUpdate()->get();
                if (DB::table('inventory_movements')->where('tenant_id', $tenant->id)->exists() || DB::table('sales')->where('tenant_id', $tenant->id)->exists() || DB::table('register_shifts')->where('tenant_id', $tenant->id)->exists()) {
                    throw ValidationException::withMessages(['currency' => 'Currency cannot change after stock, shifts or sales have been recorded. Existing amounts are not automatically converted.']);
                }
            }
            if (! empty($d['industry_key'])) {
                $industry = DB::table('industries')->where('key', $d['industry_key'])->first();
                $changes['industry_id'] = $industry->id;
                $template = json_decode($industry->template, true);
                // Defaults fill missing labels; changing industry never deletes tenant overrides or business data.
                foreach ($template['labels'] as $key => $value) {
                    DB::table('label_overrides')->insertOrIgnore(['tenant_id' => $tenant->id, 'key' => $key, 'locale' => 'en', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $tenant->update($changes);
            DB::table('onboarding_progress')->where('tenant_id', $tenant->id)->update(['step' => $d['step'], 'data' => json_encode($data), 'revision' => $revision, 'deferred_at' => $deferred ? now() : $progress->deferred_at, 'completed_at' => ($d['completed'] ?? false) ? ($progress->completed_at ?? now()) : $progress->completed_at, 'updated_at' => now()]);
            Audit::record('onboarding.saved', $tenant->id, null, ['step' => $d['step']]);

            return ['message' => 'Setup saved.', 'revision' => $revision, 'step' => $d['step']];
        });
    }

    public function modules(TenantContext $c, ModuleService $m)
    {
        return $m->listing($c->tenant->id);
    }

    public function module(Request $r, string $key, TenantContext $c, ModuleService $m)
    {
        $c->authorize('modules.manage');
        $d = $r->validate(['enabled' => 'required|boolean']);
        $m->set($c->tenant->id, $key, $d['enabled']);

        return ['message' => 'Module preference saved. Historical data is preserved.'];
    }

    public function labels(Request $r, TenantContext $c, LabelService $labels)
    {
        $d = $r->validate(['locale' => ['sometimes', Rule::in(LabelService::LOCALES)]]);
        $locale = $d['locale'] ?? $c->tenant->locale;

        return ['locale' => $locale, 'direction' => in_array($locale, ['ur', 'ar']) ? 'rtl' : 'ltr', 'labels' => DB::table('label_translations')->where('locale', 'en')->get()->map(function ($l) use ($c, $labels, $locale) {
            return ['key' => $l->key, 'section' => $l->section, 'default' => $l->value, 'value' => $labels->resolve($c->tenant->id, $l->key, $locale, $c->tenant->locale), 'override' => DB::table('label_overrides')->where('tenant_id', $c->tenant->id)->where('key', $l->key)->where('locale', $locale)->value('value')];
        })];
    }

    public function saveLabels(Request $r, TenantContext $c)
    {
        $c->authorize('labels.manage');
        $d = $r->validate(['locale' => ['required', Rule::in(LabelService::LOCALES)], 'labels' => 'required|array|max:300', 'labels.*' => 'nullable|string|max:150']);
        $keys = DB::table('label_translations')->where('locale', 'en')->pluck('key')->all();
        foreach (array_keys($d['labels']) as $key) {
            if (! in_array($key, $keys)) {
                throw ValidationException::withMessages(['labels' => 'Unknown customizable label: '.$key]);
            }
        }
        DB::transaction(function () use ($d, $c) {
            foreach ($d['labels'] as $key => $value) {
                $q = DB::table('label_overrides')->where('tenant_id', $c->tenant->id)->where('key', $key)->where('locale', $d['locale']);
                if ($value === null || $value === '') {
                    $q->delete();
                } else {
                    DB::table('label_overrides')->updateOrInsert(['tenant_id' => $c->tenant->id, 'key' => $key, 'locale' => $d['locale']], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            Audit::record('labels.updated', $c->tenant->id, null, ['locale' => $d['locale'], 'keys' => array_keys($d['labels'])]);
        });

        return ['message' => 'Labels saved.'];
    }

    public function resetLabels(Request $r, TenantContext $c)
    {
        $c->authorize('labels.manage');
        $d = $r->validate(['locale' => ['required', Rule::in(LabelService::LOCALES)], 'section' => 'required|in:navigation,actions']);
        DB::table('label_overrides')->where('tenant_id', $c->tenant->id)->where('locale', $d['locale'])->whereIn('key', DB::table('label_translations')->where('section', $d['section'])->pluck('key'))->delete();
        Audit::record('labels.section_reset', $c->tenant->id, $d['section'], ['locale' => $d['locale']]);

        return ['message' => 'Section restored.'];
    }
}
