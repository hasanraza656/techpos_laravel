<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $tenant->status === 'active' && ! $tenant->trashed() && DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
    }
}
