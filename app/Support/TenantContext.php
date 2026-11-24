<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TenantContext
{
    public Tenant $tenant;

    public User $user;

    public bool $owner = false;

    public function allows(string $permission, ?int $branchId = null): bool
    {
        if ($this->owner) {
            return true;
        }

        return DB::table('user_role_assignments as a')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.tenant_id', $this->tenant->id)->where('a.user_id', $this->user->id)->where('p.key', $permission)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('a.branch_id');
                if ($branchId !== null) {
                    $q->orWhere('a.branch_id', $branchId);
                }
            })->exists();
    }

    public function authorize(string $permission, ?int $branchId = null): void
    {
        abort_unless($this->allows($permission, $branchId), 403, 'You do not have permission for this action.');
    }

    /**
     * Granted tenant-wide or in at least one active branch. Only for reading tenant-shared data
     * (e.g. the product catalog) that a branch-scoped role already lists per branch; writes to
     * shared records keep requiring a tenant-wide grant.
     */
    public function allowsInAnyBranch(string $permission): bool
    {
        if ($this->owner) {
            return true;
        }

        return DB::table('user_role_assignments as a')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->where('a.tenant_id', $this->tenant->id)->where('a.user_id', $this->user->id)->where('p.key', $permission)
            ->where(fn ($q) => $q->whereNull('a.branch_id')->orWhereNull('b.deleted_at'))
            ->exists();
    }
}
