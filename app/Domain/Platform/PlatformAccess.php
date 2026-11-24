<?php

namespace App\Domain\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlatformAccess
{
    public const PERMISSIONS = [
        'overview.view' => ['Dashboard', 'View platform dashboard'],
        'clients.view' => ['Clients', 'View client profiles and usage'],
        'clients.manage' => ['Clients', 'Create, update, suspend and archive clients'],
        'inventory.view' => ['Inventory', 'Read inventory across clients'],
        'inventory.cost' => ['Inventory', 'View client inventory costs and valuations'],
        'client_sales.view' => ['Inventory', 'Read client sales and receipt documents'],
        'subscriptions.view' => ['Subscriptions', 'View plans, renewal dates and subscription history'],
        'subscriptions.manage' => ['Subscriptions', 'Manage lifecycle, grace and downgrades'],
        'payments.view' => ['Billing', 'View payments and private proofs'],
        'payments.review' => ['Billing', 'Approve, reject or request information on payments'],
        'billing.view' => ['Billing', 'View billing invoices'],
        'billing.manage' => ['Billing', 'Issue and void unpaid invoices'],
        'users.view' => ['Users', 'View users and business memberships'],
        'users.manage' => ['Users', 'Create/edit users and manage account access'],
        'client_access.view' => ['Access', 'Inspect tenant roles, permissions and assignments'],
        'client_access.manage' => ['Access', 'Manage tenant roles and member assignments'],
        'access.view' => ['Access', 'View platform roles and staff permissions'],
        'access.manage' => ['Access', 'Manage platform roles and staff assignments'],
        'plans.view' => ['Plans', 'View plan catalog, prices, limits and features'],
        'plans.manage' => ['Plans', 'Edit plan prices, limits and entitlements'],
        'settings.view' => ['Settings', 'View platform configuration and payment instructions'],
        'settings.manage' => ['Settings', 'Update billing identity and payment instructions'],
        'leads.view' => ['Leads', 'Read inbound business leads'],
        'leads.manage' => ['Leads', 'Update lead follow-up status'],
        'audit.view' => ['Security', 'Inspect platform and tenant audit events'],
    ];

    public const TEMPLATES = [
        'Billing manager' => ['overview.view', 'clients.view', 'subscriptions.view', 'subscriptions.manage', 'payments.view', 'payments.review', 'billing.view', 'billing.manage', 'plans.view'],
        'Support specialist' => ['overview.view', 'clients.view', 'inventory.view', 'users.view', 'client_access.view'],
        'Read-only auditor' => ['overview.view', 'clients.view', 'inventory.view', 'subscriptions.view', 'payments.view', 'billing.view', 'users.view', 'client_access.view', 'access.view', 'plans.view', 'audit.view'],
    ];

    public static function validateDependencies(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $required = match (true) {
                str_ends_with($permission, '.manage') => [str_replace('.manage', '.view', $permission)],
                $permission === 'payments.review' => ['payments.view'],
                $permission === 'inventory.cost' => ['inventory.view'],
                default => [],
            };
            abort_if(array_diff($required, $permissions), 422, 'Include '.implode(', ', $required).' when granting '.$permission.'.');
        }
    }

    public function permissions(User $user): array
    {
        if (($user->status ?? 'active') !== 'active' || ! $user->hasVerifiedEmail()) {
            return [];
        }
        if ($user->is_superadmin) {
            return array_keys(self::PERMISSIONS);
        }

        return DB::table('platform_user_roles as a')->join('platform_role_permissions as p', 'p.role_id', '=', 'a.role_id')->where('a.user_id', $user->id)->distinct()->pluck('p.permission')->all();
    }

    /** Permissions granted by a user's staff roles, regardless of account status. */
    public function rolePermissions(int $userId): array
    {
        return DB::table('platform_user_roles as a')->join('platform_role_permissions as p', 'p.role_id', '=', 'a.role_id')->where('a.user_id', $userId)->distinct()->pluck('p.permission')->all();
    }

    /** A delegated administrator may only manage staff whose authority they fully hold. */
    public function assertCovers(User $actor, array $permissions): void
    {
        abort_if(array_diff($permissions, $this->permissions($actor)), 403, 'You cannot manage staff or roles holding platform permissions you do not have.');
    }

    public function enter(User $user): bool
    {
        return $this->permissions($user) !== [];
    }

    public function authorize(User $user, string $permission): void
    {
        abort_unless(in_array($permission, $this->permissions($user), true), 403, 'Your platform role does not permit this action.');
    }

    public function identity(User $user): array
    {
        return array_merge($user->toArray(), ['platform_access' => $this->enter($user)]);
    }
}
