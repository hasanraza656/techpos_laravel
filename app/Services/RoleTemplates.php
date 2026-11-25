<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoleTemplates
{
    public static function all(): array
    {
        $all = DB::table('permissions')->pluck('key')->all();

        return [
            'Business Admin' => $all,
            'Branch Manager' => ['products.view', 'products.edit', 'sales.view', 'sales.create', 'sales.discount', 'sales.refund', 'register.open_drawer', 'register.close', 'reports.view', 'employees.view', 'branches.view', 'customers.credit_sell', 'customers.collect', 'customers.credit_manage'],
            'Cashier' => ['products.view', 'sales.create', 'sales.view', 'register.open_drawer', 'customers.credit_sell', 'customers.collect'],
            'Inventory Manager' => ['products.view', 'products.create', 'products.edit', 'products.delete', 'products.view_cost', 'inventory.view', 'inventory.adjust'],
            'Accountant' => ['reports.view', 'reports.view_profit', 'reports.export', 'sales.view'],
            'Purchasing Officer' => ['products.view', 'products.create', 'products.view_cost'],
            'Sales Manager' => ['products.view', 'sales.view', 'sales.create', 'sales.discount', 'sales.refund', 'reports.view', 'customers.credit_sell', 'customers.collect'],
            'Customer Support' => ['sales.view', 'products.view'],
            'Read-only Auditor' => ['products.view', 'sales.view', 'reports.view', 'reports.view_profit'],
        ];
    }

    public function seed(int $tenantId): void
    {
        foreach (self::all() as $name => $keys) {
            $id = DB::table('roles')->insertGetId(['tenant_id' => $tenantId, 'public_id' => (string) Str::uuid(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $id, 'permission_id' => $permission]);
            }
        }
    }
}
