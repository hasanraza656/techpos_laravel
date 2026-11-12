<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    private function defaults(string $table, array $identity, array $values): void
    {
        if (! DB::table($table)->where($identity)->exists()) {
            DB::table($table)->insert(array_merge($identity, $values));
        }
    }

    public function run(): void
    {
        $features = [
            'pos' => ['Point of sale', 'Sales', []], 'inventory' => ['Basic inventory', 'Inventory', []], 'customers' => ['Customer directory', 'Customers', []],
            'receipts' => ['Receipts', 'Sales', ['pos']], 'offline_basic' => ['Offline foundation', 'Operations', ['pos']], 'daily_summary' => ['Daily summary', 'Reports', []],
            'purchases' => ['Purchasing', 'Inventory', ['inventory']], 'suppliers' => ['Suppliers', 'Inventory', []], 'loyalty' => ['Customer loyalty', 'Customers', ['customers']],
            'promotions' => ['Discounts and promotions', 'Sales', ['pos']], 'expenses' => ['Expense tracking', 'Finance', []], 'reports' => ['Advanced reporting', 'Reports', []],
            'returns' => ['Returns and exchanges', 'Sales', ['pos']], 'bulk_data' => ['Import and export', 'Operations', ['inventory']],
            'batches' => ['Batch tracking', 'Pharmacy', ['inventory']], 'expiry' => ['Expiry tracking', 'Pharmacy', ['batches']],
            'tables' => ['Tables', 'Restaurant', ['pos']], 'kitchen' => ['Kitchen orders', 'Restaurant', ['tables']], 'modifiers' => ['Menu modifiers', 'Restaurant', ['pos']],
            'appointments' => ['Appointments', 'Services', []], 'staff_scheduling' => ['Staff scheduling', 'Services', ['appointments']],
            'stations' => ['Gaming stations', 'Gaming', []], 'session_timers' => ['Session timers', 'Gaming', ['stations']], 'hourly_billing' => ['Hourly billing', 'Gaming', ['session_timers']],
            'offline_advanced' => ['Advanced offline foundation', 'Operations', ['offline_basic']], 'warehouses' => ['Multiple warehouses', 'Inventory', ['inventory']],
            'analytics' => ['Advanced analytics', 'Reports', ['reports']], 'forecasting' => ['Forecasting framework', 'Intelligence', ['analytics']],
            'purchase_suggestions' => ['Purchase suggestions framework', 'Intelligence', ['purchases']], 'ai_framework' => ['AI integration framework', 'Intelligence', []],
            'advanced_permissions' => ['Advanced permissions', 'Administration', []], 'multi_branch_reports' => ['Multi-branch reports', 'Reports', ['reports']],
            'accounting' => ['Accounting enhancements', 'Finance', []], 'api_access' => ['API access', 'Integrations', []], 'custom_reports' => ['Custom reports', 'Reports', ['reports']],
            'integrations' => ['Advanced integrations', 'Integrations', []], 'priority_support' => ['Priority support', 'Administration', []],
        ];
        $features['builtin_intelligence'] = ['Built-in Intelligence', 'Reports', ['daily_summary']];
        foreach ($features as $key => [$name,$category,$deps]) {
            $this->defaults('features', ['key' => $key], ['name' => $name, 'category' => $category, 'description' => $name.' capability. Entitlement does not indicate workflow implementation.', 'dependencies' => json_encode($deps), 'requires_configuration' => in_array($key, ['integrations', 'ai_framework'])]);
        }
        foreach ([
            ['free', 'Free', 0, ['businesses' => 1, 'branches' => 1, 'users' => 2, 'cashiers' => 1, 'active_skus' => 100, 'history_days' => 30], array_slice(array_keys($features), 0, 6)],
            ['standard', 'Standard', 5000, ['businesses' => 1, 'branches' => 3, 'users' => 10, 'cashiers' => 10, 'active_skus' => 10000, 'history_days' => 365], array_slice(array_keys($features), 0, 25)],
            ['premium', 'Premium', 10000, ['businesses' => 1, 'branches' => 20, 'users' => 100, 'cashiers' => 50, 'active_skus' => 100000, 'history_days' => 3650], array_keys($features)],
        ] as [$key,$name,$price,$limits,$included]) {
            $isNewPlan = ! DB::table('plans')->where('key', $key)->exists();
            $this->defaults('plans', ['key' => $key], ['name' => $name, 'description' => $key === 'free' ? 'A useful permanent starting plan.' : 'Monthly '.$name.' entitlements.', 'limits' => json_encode($limits), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('plans')->where('key', $key)->value('id');
            $this->defaults('plan_prices', ['plan_id' => $id, 'currency' => 'PKR', 'interval' => 'month'], ['amount' => $price, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($isNewPlan ? array_unique([...$included, 'builtin_intelligence']) : [] as $f) {
                $this->defaults('feature_entitlements', ['plan_id' => $id, 'feature_id' => DB::table('features')->where('key', $f)->value('id')], []);
            }
        }
        $industries = [
            'superstore' => ['Supermarket / Superstore', ['pos', 'inventory', 'purchases'], []], 'grocery' => ['Grocery / General Store', ['pos', 'inventory'], []],
            'pharmacy' => ['Pharmacy', ['inventory', 'batches', 'expiry', 'suppliers'], ['product' => 'Medicine', 'stock' => 'Medicine Inventory']],
            'restaurant' => ['Restaurant', ['pos', 'tables', 'kitchen', 'modifiers'], ['product' => 'Menu Item', 'sale' => 'Order', 'cashier' => 'Order Desk']],
            'cafe' => ['Cafe / Coffee Shop', ['pos', 'modifiers'], ['product' => 'Menu Item']], 'bakery' => ['Bakery', ['pos', 'inventory', 'batches'], []],
            'fashion' => ['Clothing / Fashion', ['pos', 'inventory'], []], 'electronics' => ['Electronics', ['pos', 'inventory'], []],
            'electrical' => ['Electrical Supplies', ['inventory', 'purchases'], []], 'mobile' => ['Mobile Phone Shop', ['pos', 'inventory'], []],
            'hardware' => ['Hardware Store', ['inventory', 'purchases'], []], 'beauty' => ['Cosmetics / Beauty', ['pos', 'inventory'], []],
            'salon' => ['Salon / Barber Shop', ['appointments', 'staff_scheduling'], ['product' => 'Service', 'customer' => 'Client', 'sale' => 'Invoice']],
            'gaming' => ['Gaming Zone', ['stations', 'session_timers', 'hourly_billing'], []], 'bookstore' => ['Bookstore / Stationery', ['pos', 'inventory'], []],
            'furniture' => ['Furniture', ['inventory', 'customers'], []], 'automobile' => ['Automobile Parts', ['inventory', 'suppliers'], []],
            'wholesale' => ['Wholesale Distributor', ['inventory', 'purchases', 'suppliers'], []], 'jewelry' => ['Jewelry', ['pos', 'inventory'], []],
            'pet_store' => ['Pet Store', ['pos', 'inventory'], []], 'services' => ['Service Business', ['customers', 'appointments'], ['product' => 'Service']],
            'invoice_only' => ['Invoice-Only Business', ['customers'], ['sale' => 'Invoice']], 'other' => ['Other / Custom Business', ['pos', 'inventory'], []],
        ];
        foreach ($industries as $key => [$name,$modules,$labels]) {
            $this->defaults('industries', ['key' => $key], ['name' => $name, 'description' => 'Personalize your setup for '.strtolower($name).'.', 'template' => json_encode(['recommended_modules' => $modules, 'labels' => $labels, 'navigation' => $modules, 'product_fields' => [], 'reports' => ['daily_summary'], 'workflows' => [], 'widgets' => [], 'settings' => []]), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['products' => ['view', 'create', 'edit', 'delete', 'change_price', 'view_cost'], 'sales' => ['view', 'create', 'discount', 'refund', 'void'], 'register' => ['open_drawer', 'close'], 'reports' => ['view', 'view_profit', 'export'], 'employees' => ['view', 'manage'], 'roles' => ['manage'], 'subscriptions' => ['view', 'manage'], 'settings' => ['manage', 'tax', 'integrations'], 'branches' => ['view', 'manage'], 'modules' => ['manage'], 'labels' => ['manage'], 'onboarding' => ['manage']] as $module => $actions) {
            foreach ($actions as $action) {
                $name = ucfirst(str_replace('_', ' ', $action)).' '.$module;
                $this->defaults('permissions', ['key' => "$module.$action"], ['module' => $module, 'name' => $name, 'description' => $name.' within the assigned business and branch scope.']);
            }
        }
        foreach (['product' => 'Product', 'customer' => 'Customer', 'sale' => 'Sale', 'cashier' => 'Cashier', 'stock' => 'Stock', 'dashboard' => 'Dashboard', 'settings' => 'Settings', 'subscription' => 'Subscription', 'save' => 'Save changes', 'cancel' => 'Cancel'] as $key => $value) {
            $this->defaults('label_translations', ['key' => $key, 'locale' => 'en'], ['section' => in_array($key, ['save', 'cancel']) ? 'actions' : 'navigation', 'value' => $value, 'review_status' => 'reviewed', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['bank', 'Bank Transfer', ['bank' => 'Meezan Bank', 'account_number' => '02470106959561', 'account_holder' => 'HASSAN RAZA']], ['jazzcash', 'JazzCash', ['account_number' => '03403877126']], ['easypaisa', 'Easypaisa', ['account_number' => '03156298337']]] as [$key,$name,$details]) {
            $this->defaults('payment_methods', ['key' => $key], ['name' => $name, 'details' => json_encode($details), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
