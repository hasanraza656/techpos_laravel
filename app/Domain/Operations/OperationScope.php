<?php

namespace App\Domain\Operations;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationScope
{
    public function __construct(public TenantContext $context) {}

    public function branch(string $uuid, string $permission): object
    {
        $b = DB::table('branches')->where('tenant_id', $this->context->tenant->id)->where('public_id', $uuid)->whereNull('deleted_at')->first();
        abort_unless($b, 404, 'Branch not found.');
        $this->context->authorize($permission, $b->id);

        return $b;
    }

    public function warehouse(string $uuid, int $branch): object
    {
        $w = DB::table('warehouses')->where('tenant_id', $this->context->tenant->id)->where('branch_id', $branch)->where('public_id', $uuid)->first();
        abort_unless($w, 404, 'Warehouse not found in this branch.');

        return $w;
    }

    public function defaults(int $tenantId, int $branchId): void
    {
        foreach (['warehouses' => 'Main stockroom', 'registers' => 'Register 1'] as $table => $name) {
            if (! DB::table($table)->where('tenant_id', $tenantId)->where('branch_id', $branchId)->exists()) {
                DB::table($table)->insert(['tenant_id' => $tenantId, 'branch_id' => $branchId, 'public_id' => (string) Str::uuid(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
