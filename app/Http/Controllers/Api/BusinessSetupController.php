<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\Decimal;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BusinessSetupController extends Controller
{
    public function checklist(TenantContext $c)
    {
        $id = $c->tenant->id;
        $settings = $c->tenant->settings ?? [];
        $items = [
            ['key' => 'product', 'title' => 'Add your first product', 'description' => 'Set a price and add a barcode or photo.', 'to' => '/catalog', 'permission' => 'products.create', 'done' => DB::table('products')->where('tenant_id', $id)->whereNull('deleted_at')->exists()],
            ['key' => 'customer', 'title' => 'Add your first customer', 'description' => 'Keep regular customers easy to find.', 'to' => '/customers', 'permission' => 'customers.manage', 'done' => DB::table('customers')->where('tenant_id', $id)->whereNull('deleted_at')->exists()],
            ['key' => 'tax', 'title' => 'Review your tax settings', 'description' => 'Choose defaults for new products.', 'to' => '/settings', 'permission' => 'settings.tax', 'done' => ! empty($settings['tax_profile']['configured_at'])],
            ['key' => 'sale', 'title' => 'Make your first sale', 'description' => 'Open a register and try checkout.', 'to' => '/pos', 'permission' => 'sales.create', 'done' => DB::table('sales')->where('tenant_id', $id)->exists()],
        ];

        return ['items' => array_values(array_filter($items, function ($item) use ($c, $id) {
            if ($c->allows($item['permission'])) {
                return true;
            }
            foreach (DB::table('branches')->where('tenant_id', $id)->whereNull('deleted_at')->pluck('id') as $branch) {
                if ($c->allows($item['permission'], $branch)) {
                    return true;
                }
            }

            return false;
        }))];
    }

    public function tax(TenantContext $c)
    {
        $c->authorize('settings.tax');

        return ['tax_profile' => $c->tenant->settings['tax_profile'] ?? ['name' => 'Tax', 'default_rate' => '0.0000', 'tax_inclusive' => false, 'registration_number' => '', 'configured_at' => null]];
    }

    public function saveTax(Request $r, TenantContext $c)
    {
        $c->authorize('settings.tax');
        $d = $r->validate(['name' => 'required|string|max:80', 'default_rate' => ['required', 'string', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'], 'tax_inclusive' => 'required|boolean', 'registration_number' => 'nullable|string|max:100']);
        $d['default_rate'] = Decimal::input($d['default_rate'], 4);
        if (bccomp($d['default_rate'], '100', 4) > 0) {
            Decimal::fail('default_rate', 'Choose a rate between 0 and 100 percent.');
        }

        return DB::transaction(function () use ($c, $d) {
            $tenant = Tenant::whereKey($c->tenant->id)->lockForUpdate()->firstOrFail();
            $profile = $d + ['configured_at' => now()->toIso8601String()];
            $settings = $tenant->settings ?? [];
            $settings['tax_profile'] = $profile;
            $tenant->update(['settings' => $settings]);
            Audit::record('settings.tax_updated', $tenant->id, $tenant->public_id, ['default_rate' => $d['default_rate'], 'tax_inclusive' => $d['tax_inclusive']]);

            return ['tax_profile' => $profile];
        });
    }
}
