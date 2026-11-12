<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Platform\BillingInvoices;
use App\Domain\Platform\PlatformAccess;
use App\Domain\Platform\PlatformCommand;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\TenantService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class ManageController extends Controller
{
    private function client(string $id): object
    {
        $t = DB::table('tenants')->where('public_id', $id)->lockForUpdate()->first();
        abort_unless($t, 404);

        return $t;
    }

    private function revoke(int $user): void
    {
        DB::table('users')->where('id', $user)->update(['remember_token' => Str::random(60), 'updated_at' => now()]);
        foreach (DB::table('sessions')->where('user_id', $user)->pluck('id') as $session) {
            AccountSecurity::revoke($session, $user);
        }DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user)->delete();
        DB::table('devices')->where('user_id', $user)->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    private function safeTarget(Request $r, string $id): User
    {
        $u = User::where('public_id', $id)->lockForUpdate()->firstOrFail();
        abort_if($u->is_superadmin && ! $r->user()->is_superadmin, 403, 'Only a root administrator can manage another root.');

        return $u;
    }

    private function userData(Request $r, ?User $u = null): array
    {
        return $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($u?->id)], 'new_password' => [$u ? 'nullable' : 'required', 'string', Password::min(12)->letters()->numbers()]]);
    }

    public function createUser(Request $r, PlatformCommand $cmd)
    {
        return $cmd->run($r, 'users.manage', function () use ($r) {
            $d = $this->userData($r);
            $u = User::create(['name' => $d['name'], 'email' => mb_strtolower($d['email']), 'password' => $d['new_password']]);
            Audit::record('platform.user_created', null, $u->public_id);

            return ['public_id' => $u->public_id, 'message' => 'User created. Email verification is required.'];
        });
    }

    public function updateUser(Request $r, string $user, PlatformCommand $cmd)
    {
        $u = User::where('public_id', $user)->firstOrFail();
        $d = $this->userData($r, $u);

        return $cmd->run($r, 'users.manage', function () use ($r, $user, $d) {
            $u = $this->safeTarget($r, $user);
            abort_if($u->id === $r->user()->id && ($u->email !== mb_strtolower($d['email']) || ! empty($d['new_password'])), 422, 'Use Account security to change your own sign-in details.');
            $email = mb_strtolower($d['email']);
            $changed = $email !== $u->email;
            if ($changed) {
                abort_if($u->is_superadmin && User::where('is_superadmin', true)->where('status', 'active')->whereNotNull('email_verified_at')->where('id', '!=', $u->id)->count() === 0, 422, 'Keep another active verified root before changing this email.');
                $u->email_verified_at = null;
            }$u->fill(['name' => $d['name'], 'email' => $email]);
            if (! empty($d['new_password'])) {
                $u->password = $d['new_password'];
            }$u->save();
            if ($changed || ! empty($d['new_password'])) {
                $this->revoke($u->id);
            }Audit::record('platform.user_updated', null, $u->public_id, ['email_changed' => $changed, 'password_rotated' => ! empty($d['new_password'])]);

            return ['message' => 'User updated.'];
        });
    }

    public function userState(Request $r, string $user, PlatformCommand $cmd)
    {
        $d = $r->validate(['action' => 'required|in:suspend,activate,archive,verify,revoke_sessions']);

        return $cmd->run($r, 'users.manage', function () use ($r, $user, $d) {
            $u = $this->safeTarget($r, $user);
            $action = $d['action'];
            abort_if($u->id === $r->user()->id && $action !== 'verify', 422, 'Use your account controls; you cannot disable your own administrator session here.');
            if (in_array($action, ['suspend', 'archive'])) {
                abort_if($u->is_superadmin && User::where('is_superadmin', true)->where('status', 'active')->whereNotNull('email_verified_at')->where('id', '!=', $u->id)->count() === 0, 422, 'The last active verified root must remain available.');
                $owned = DB::table('tenant_users as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('m.user_id', $u->id)->where('m.is_owner', true)->where('t.status', 'active')->whereNull('t.deleted_at')->pluck('t.id');
                foreach ($owned as $tenant) {
                    $other = DB::table('tenant_users as m')->join('users as other', 'other.id', '=', 'm.user_id')->where('m.tenant_id', $tenant)->where('m.is_owner', true)->where('m.user_id', '!=', $u->id)->where('other.status', 'active')->exists();
                    abort_unless($other, 422, 'Suspend the owned client first or retain another active owner before disabling this user.');
                }
            }
            if ($action === 'verify') {
                $u->email_verified_at = now();
            } elseif ($action !== 'revoke_sessions') {
                $u->status = match ($action) {
                    'activate' => 'active','suspend' => 'suspended',default => 'archived'
                };
                $u->archived_at = $action === 'archive' ? now() : null;
            }$u->save();
            if ($action !== 'verify') {
                $this->revoke($u->id);
            }Audit::record('platform.user_'.$action, null, $u->public_id, ['reason' => $r->input('reason')]);

            return ['message' => 'User action completed.'];
        });
    }

    public function createClient(Request $r, PlatformCommand $cmd)
    {
        return $cmd->run($r, 'clients.manage', function () use ($r) {
            $d = $r->validate(['name' => 'required|string|max:150', 'owner' => 'nullable|uuid|exists:users,public_id', 'owner_name' => 'required_without:owner|nullable|string|max:100', 'owner_email' => ['required_without:owner', 'nullable', 'email', 'unique:users,email'], 'owner_password' => ['required_without:owner', 'nullable', Password::min(12)->letters()->numbers()], 'country' => 'required|string|size:2', 'currency' => 'required|in:PKR,USD,GBP,EUR,AED,SAR,INR', 'timezone' => 'required|timezone', 'industry_key' => 'nullable|exists:industries,key']);
            if (! empty($d['owner'])) {
                $u = User::where('public_id', $d['owner'])->where('status', 'active')->firstOrFail();
            } else {
                app(PlatformAccess::class)->authorize($r->user(), 'users.manage');
                $u = User::create(['name' => $d['owner_name'], 'email' => mb_strtolower($d['owner_email']), 'password' => $d['owner_password']]);
            }$t = app(TenantService::class)->create($u, $d['name']);
            $t->update(['country' => strtoupper($d['country']), 'currency' => $d['currency'], 'timezone' => $d['timezone'], 'industry_id' => empty($d['industry_key']) ? null : DB::table('industries')->where('key', $d['industry_key'])->value('id')]);
            Audit::record('platform.client_created', $t->id, $t->public_id);

            return ['public_id' => $t->public_id, 'message' => 'Client created with its owner and Free subscription.'];
        });
    }

    public function updateClient(Request $r, string $client, PlatformCommand $cmd)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'country' => 'required|string|size:2', 'timezone' => 'required|timezone', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:254', 'address' => 'nullable|string|max:1000', 'platform_notes' => 'nullable|string|max:3000']);

        return $cmd->run($r, 'clients.manage', function () use ($client, $d) {
            $t = $this->client($client);
            $settings = json_decode($t->settings ?? '{}', true);
            $settings['client_contact'] = array_intersect_key($d, array_flip(['phone', 'email', 'address']));
            DB::table('tenants')->where('id', $t->id)->update(['name' => $d['name'], 'country' => strtoupper($d['country']), 'timezone' => $d['timezone'], 'platform_notes' => $d['platform_notes'] ?? null, 'settings' => json_encode($settings), 'updated_at' => now()]);
            Audit::record('platform.client_updated', $t->id, $client);

            return ['message' => 'Client profile saved.'];
        });
    }

    public function clientState(Request $r, string $client, PlatformCommand $cmd)
    {
        $d = $r->validate(['action' => 'required|in:suspend,activate,archive']);

        return $cmd->run($r, 'clients.manage', function () use ($client, $d, $r) {
            $t = $this->client($client);
            DB::table('tenants')->where('id', $t->id)->update(['status' => match ($d['action']) {
                'suspend' => 'suspended','archive' => 'archived',default => 'active'
            }, 'deleted_at' => $d['action'] === 'archive' ? now() : null, 'updated_at' => now()]);
            Audit::record('platform.client_'.$d['action'], $t->id, $client, ['reason' => $r->input('reason')]);

            return ['message' => 'Client access updated; historical records preserved.'];
        });
    }

    public function subscription(Request $r, string $client, PlatformCommand $cmd)
    {
        $d = $r->validate(['action' => 'required|in:suspend,resume,cancel,free,grace,downgrade', 'days' => 'required_if:action,grace|nullable|integer|min:1|max:30', 'plan_key' => 'required_if:action,downgrade|nullable|exists:plans,key']);

        return $cmd->run($r, 'subscriptions.manage', function () use ($r, $client, $d) {
            $t = $this->client($client);
            $s = DB::table('subscriptions')->where('tenant_id', $t->id)->lockForUpdate()->first();
            abort_unless($s, 404);
            $before = (array) $s;
            $change = ['updated_at' => now()];
            $plan = DB::table('plans')->find($s->plan_id);
            switch ($d['action']) {
                case 'suspend':abort_unless(in_array($s->status, ['active', 'grace']), 422, 'Only an active or grace subscription can be suspended.');
                    $change += ['status' => 'suspended', 'status_before_suspension' => $s->status];
                    break;
                case 'resume':abort_unless($s->status === 'suspended', 422, 'This subscription is not suspended.');
                    $change += ['status' => $s->status_before_suspension ?? 'active', 'status_before_suspension' => null];
                    break;
                case 'cancel':$change += ['status' => 'cancelled', 'grace_ends_at' => null];
                    break;
                case 'free':$change += ['plan_id' => DB::table('plans')->where('key', 'free')->value('id'), 'status' => 'active', 'starts_at' => now(), 'ends_at' => null, 'grace_ends_at' => null];
                    break;
                case 'grace':abort_unless($plan->key !== 'free' && DB::table('subscription_payments')->where('tenant_id', $t->id)->where('status', 'approved')->exists(), 422, 'Grace requires a previously approved paid subscription.');
                    $change += ['status' => 'grace', 'grace_ends_at' => now()->addDays($d['days'])];
                    break;
                case 'downgrade':$target = DB::table('plans')->where('key', $d['plan_key'])->where('active', true)->first();
                    abort_unless($target && $s->ends_at && Carbon::parse($s->ends_at)->isFuture() && in_array($s->status, ['active', 'grace']), 422, 'A downgrade requires a current paid term.');
                    $current = DB::table('feature_entitlements')->where('plan_id', $s->plan_id)->pluck('feature_id')->all();
                    $next = DB::table('feature_entitlements')->where('plan_id', $target->id)->pluck('feature_id')->all();
                    abort_if(array_diff($next, $current), 422, 'The target adds entitlements; use verified payment approval for upgrades.');
                    foreach (json_decode($target->limits, true) as $key => $limit) {
                        abort_if($limit > (json_decode($plan->limits, true)[$key] ?? 0), 422, 'The target increases limits; it is not a downgrade.');
                    }$change += ['plan_id' => $target->id];
                    break;
            }DB::table('subscriptions')->where('id', $s->id)->update($change);
            $after = DB::table('subscriptions')->find($s->id);
            DB::table('subscription_events')->insert(['tenant_id' => $t->id, 'actor_id' => $r->user()->id, 'action' => $d['action'], 'before_state' => json_encode($before), 'after_state' => json_encode($after), 'reason' => $r->input('reason'), 'created_at' => now()]);
            Audit::record('platform.subscription_'.$d['action'], $t->id, $client, ['reason' => $r->input('reason')]);

            return ['message' => 'Subscription updated. No client records were deleted.'];
        });
    }

    public function issueInvoice(Request $r, PlatformCommand $cmd)
    {
        $d = $r->validate(['client' => 'required|uuid|exists:tenants,public_id', 'plan_key' => 'required|exists:plans,key', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:today']);

        return $cmd->run($r, 'billing.manage', function () use ($r, $d) {
            $t = $this->client($d['client']);
            $p = DB::table('plans')->where('key', $d['plan_key'])->where('active', true)->first();
            abort_unless($p, 422);
            $invoice = app(BillingInvoices::class)->issue($t->id, $p->id, $r->input('request_key'), $d['due_on']);

            return ['public_id' => $invoice->public_id, 'number' => $invoice->number, 'message' => 'Invoice issued. Paid access starts only after payment approval.'];
        });
    }

    public function voidInvoice(Request $r, string $invoice, PlatformCommand $cmd)
    {
        return $cmd->run($r, 'billing.manage', function () use ($r, $invoice) {
            $b = DB::table('billing_invoices')->where('public_id', $invoice)->first();
            abort_unless($b, 404);
            DB::table('tenants')->where('id', $b->tenant_id)->lockForUpdate()->first();
            $b = DB::table('billing_invoices')->where('id', $b->id)->lockForUpdate()->first();
            abort_unless($b->status === 'issued', 422, 'Only unpaid issued invoices can be voided.');
            if ($b->payment_id) {
                $p = DB::table('subscription_payments')->find($b->payment_id);
                abort_if(in_array($p->status, ['approved', 'pending_review', 'needs_information']), 422, 'Review the linked payment before voiding its invoice.');
                if ($p->status === 'draft') {
                    DB::table('subscription_payments')->where('id', $p->id)->update(['status' => 'expired', 'updated_at' => now()]);
                    DB::table('payment_events')->insert(['subscription_payment_id' => $p->id, 'status' => 'expired', 'note' => 'Billing invoice voided', 'created_at' => now()]);
                }
            }DB::table('billing_invoices')->where('id', $b->id)->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $r->input('reason'), 'updated_at' => now()]);
            Audit::record('billing.invoice_voided', $b->tenant_id, $invoice, ['reason' => $r->input('reason')]);

            return ['message' => 'Unpaid invoice voided.'];
        });
    }

    public function entitlements()
    {
        return ['features' => DB::table('features')->orderBy('category')->get(), 'entitlements' => DB::table('feature_entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')->join('features as f', 'f.id', '=', 'e.feature_id')->get(['p.key as plan', 'f.key as feature'])];
    }

    public function features(Request $r, string $plan, PlatformCommand $cmd)
    {
        $d = $r->validate(['features' => 'present|array', 'features.*' => 'string|distinct|exists:features,key']);

        return $cmd->run($r, 'plans.manage', function () use ($d, $plan) {
            $p = DB::table('plans')->where('key', $plan)->lockForUpdate()->first();
            abort_unless($p, 404);
            $features = DB::table('features')->whereIn('key', $d['features'])->get();
            foreach ($features as $f) {
                abort_if(array_diff(json_decode($f->dependencies, true), $d['features']), 422, 'Include dependencies for '.$f->name.'.');
            }if ($plan === 'free') {
                abort_if(array_diff(['pos', 'inventory', 'customers', 'receipts', 'daily_summary', 'offline_basic', 'builtin_intelligence'], $d['features']), 422, 'Keep the Free plan core features usable.');
            }DB::table('feature_entitlements')->where('plan_id', $p->id)->delete();
            foreach ($features as $f) {
                DB::table('feature_entitlements')->insert(['plan_id' => $p->id, 'feature_id' => $f->id]);
            }Audit::record('platform.plan_entitlements_updated', null, $plan, ['features' => $d['features']]);

            return ['message' => 'Plan entitlements updated.'];
        });
    }

    public function settings()
    {
        return ['billing_profile' => json_decode(DB::table('platform_settings')->where('key', 'billing_profile')->value('value') ?? '{"name":"Tech POS","email":"","address":"","registration_number":""}', true)];
    }

    public function saveSettings(Request $r, PlatformCommand $cmd)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => 'nullable|email|max:254', 'address' => 'nullable|string|max:1000', 'registration_number' => 'nullable|string|max:100']);

        return $cmd->run($r, 'settings.manage', function () use ($d) {
            DB::table('platform_settings')->updateOrInsert(['key' => 'billing_profile'], ['value' => json_encode($d), 'created_at' => now(), 'updated_at' => now()]);

            return ['message' => 'Billing identity saved. Issued invoices keep their original snapshots.'];
        });
    }

    public function lead(Request $r, int $lead, PlatformCommand $cmd)
    {
        $d = $r->validate(['status' => 'required|in:new,contacted,qualified,closed,spam']);

        return $cmd->run($r, 'leads.manage', function () use ($lead, $d) {
            abort_unless(DB::table('leads')->where('id', $lead)->exists(), 404);
            DB::table('leads')->where('id', $lead)->update(['status' => $d['status'], 'updated_at' => now()]);

            return ['message' => 'Lead status updated.'];
        });
    }
}
