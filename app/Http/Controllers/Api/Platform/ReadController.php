<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Platform\Filters;
use App\Domain\Platform\PlatformAccess;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReadController extends Controller
{
    private function clientsQuery()
    {
        return DB::table('tenants as t')->leftJoin('industries as i', 'i.id', '=', 't.industry_id')->leftJoin('subscriptions as s', 's.tenant_id', '=', 't.id')->leftJoin('plans as p', 'p.id', '=', 's.plan_id');
    }

    private function paid($q): void
    {
        $q->where('p.key', '!=', 'free')->whereIn('s.status', ['active', 'grace'])->where(fn ($w) => $w->whereNull('s.ends_at')->orWhere('s.ends_at', '>', now())->orWhere(fn ($g) => $g->where('s.status', 'grace')->where('s.grace_ends_at', '>', now())));
    }

    private function decorateClient(object $r): object
    {
        $valid = in_array($r->subscription_status ?? '', ['active', 'grace']) && (! $r->ends_at || Carbon::parse($r->ends_at)->isFuture() || (($r->subscription_status ?? '') === 'grace' && $r->grace_ends_at && Carbon::parse($r->grace_ends_at)->isFuture()));
        $r->effective_plan = $valid ? ($r->plan_key ?? 'free') : 'free';

        return $r;
    }

    public function me(Request $r)
    {
        $a = app(PlatformAccess::class);

        return ['user' => $a->identity($r->user()), 'permissions' => $a->permissions($r->user()), 'root' => (bool) $r->user()->is_superadmin];
    }

    public function lookup(Request $r)
    {
        $d = Filters::read($r);
        $a = app(PlatformAccess::class);
        $perms = $a->permissions($r->user());
        $out = ['clients' => [], 'plans' => [], 'users' => []];
        if (in_array('clients.view', $perms)) {
            $q = DB::table('tenants')->select('public_id', 'name', 'status')->selectSub(DB::table('tenant_users as member')->join('users as owner', 'owner.id', '=', 'member.user_id')->whereColumn('member.tenant_id', 'tenants.id')->where('member.is_owner', true)->orderBy('owner.id')->limit(1)->select('owner.email'), 'owner_email');
            Filters::search($q, $d, ['name']);
            $out['clients'] = $q->orderBy('name')->limit(30)->get();
            if ($r->filled('client')) {
                $selected = DB::table('tenants')->where('public_id', $r->input('client'))->first(['public_id', 'name', 'status']);
                $out['selected_client'] = $selected;
            }
        }
        if (in_array('plans.view', $perms) || in_array('billing.manage', $perms) || in_array('subscriptions.manage', $perms)) {
            $out['plans'] = DB::table('plans')->where('active', true)->get(['key', 'name']);
        }
        if (in_array('users.view', $perms)) {
            $q = DB::table('users')->select('public_id', 'name', 'email', 'status');
            Filters::search($q, ['q' => $d['user_q'] ?? ''], ['name', 'email']);
            $out['users'] = $q->orderBy('name')->limit(30)->get();
        }
        if (! empty($d['client']) && in_array('inventory.view', $perms)) {
            $tenant = DB::table('tenants')->where('public_id', $d['client'])->value('id');
            $out['branches'] = DB::table('branches')->where('tenant_id', $tenant)->whereNull('deleted_at')->get(['public_id', 'name']);
            $out['warehouses'] = DB::table('warehouses')->where('tenant_id', $tenant)->get(['public_id', 'branch_id', 'name']);
        }

        return $out;
    }

    public function overview(Request $r)
    {
        $d = Filters::read($r);
        $clients = DB::table('tenants as t')->whereNull('t.deleted_at');
        Filters::client($clients, $d);
        $active = $this->clientsQuery()->where('t.status', 'active')->whereNull('t.deleted_at');
        Filters::client($active, $d);
        $this->paid($active);
        $mrr = '0.00';
        foreach ((clone $active)->join('plan_prices as pp', fn ($j) => $j->on('pp.plan_id', '=', 'p.id')->where('pp.currency', 'PKR')->where('pp.interval', 'month'))->pluck('pp.amount') as $amount) {
            $mrr = bcadd($mrr, (string) $amount, 2);
        }
        $payments = DB::table('subscription_payments as sp')->join('tenants as t', 't.id', '=', 'sp.tenant_id');
        Filters::client($payments, $d);
        $collected = (clone $payments)->where('sp.status', 'approved')->where('sp.reviewed_at', '>=', now()->startOfMonth())->sum('sp.amount');
        $subs = $this->clientsQuery()->whereNull('t.deleted_at');
        Filters::client($subs, $d);
        $invoices = DB::table('billing_invoices as b')->join('tenants as t', 't.id', '=', 'b.tenant_id');
        Filters::client($invoices, $d);
        $history = [];
        for ($n = 5; $n >= 0; $n--) {
            $month = now()->startOfMonth()->subMonths($n);
            $history[] = ['month' => $month->format('M Y'), 'collected' => bcadd((string) (clone $payments)->where('sp.status', 'approved')->where('sp.reviewed_at', '>=', $month)->where('sp.reviewed_at', '<', $month->copy()->addMonth())->sum('sp.amount'), '0', 2), 'new_clients' => (clone $clients)->where('t.created_at', '>=', $month)->where('t.created_at', '<', $month->copy()->addMonth())->count()];
        }
        $products = DB::table('products as p')->join('tenants as t', 't.id', '=', 'p.tenant_id')->whereNull('p.deleted_at')->whereNull('t.deleted_at');
        Filters::client($products, $d);
        $users = DB::table('users as u');
        if (! empty($d['client'])) {
            $users->whereIn('u.id', DB::table('tenant_users as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('t.public_id', $d['client'])->select('m.user_id'));
        }
        $recent = (clone $clients)->select('t.public_id', 't.name', 't.status', 't.created_at')->orderByDesc('t.created_at')->limit(5)->get();

        return ['businesses' => (clone $clients)->count(), 'active_clients' => (clone $clients)->where('t.status', 'active')->count(), 'suspended_clients' => (clone $clients)->where('t.status', 'suspended')->count(),
            'active_paid' => (clone $active)->count(), 'free_clients' => max(0, (clone $clients)->where('t.status', 'active')->count() - (clone $active)->count()), 'users' => $users->count(), 'products' => (clone $products)->where('p.active', true)->count(),
            'pending_payments' => (clone $payments)->where('sp.status', 'pending_review')->count(), 'mrr' => $mrr, 'arr' => bcmul($mrr, '12', 2), 'collected_this_month' => bcadd((string) $collected, '0', 2),
            'renewals_due' => (clone $subs)->where('p.key', '!=', 'free')->whereIn('s.status', ['active', 'grace'])->whereBetween('s.ends_at', [now(), now()->addDays(14)])->count(),
            'overdue_invoices' => (clone $invoices)->where('b.status', 'issued')->where('b.due_on', '<', now()->toDateString())->count(), 'history' => $history, 'recent_clients' => $recent,
            'industry_distribution' => (clone $clients)->leftJoin('industries as i', 'i.id', '=', 't.industry_id')->groupBy('i.name')->selectRaw('i.name,COUNT(*) as count')->get(),
            'definitions' => ['mrr' => 'Current monthly PKR catalog run rate for active clients with unexpired paid rights; excludes unpaid invoices and pending proof.', 'collected' => 'Approved subscription payment amounts by review date; distinct from recurring run rate.', 'dates' => 'Platform trends use UTC. Client operational totals retain their own currency.']];
    }

    public function clients(Request $r)
    {
        $d = Filters::read($r);
        $q = $this->clientsQuery();
        Filters::client($q, $d);
        Filters::search($q, $d, ['t.name']);
        if (($d['status'] ?? '') === 'archived') {
            $q->whereNotNull('t.deleted_at');
        } else {
            $q->whereNull('t.deleted_at');
            if (! empty($d['status'])) {
                $q->where('t.status', $d['status']);
            }
        }
        if (! empty($d['plan'])) {
            $q->where('p.key', $d['plan']);
        }
        $q->select('t.public_id', 't.name', 't.status', 't.currency', 't.country', 't.timezone', 't.created_at', 't.deleted_at', 'i.name as industry', 'p.key as plan_key', 'p.name as plan_name', 's.status as subscription_status', 's.ends_at', 's.grace_ends_at')
            ->selectSub(DB::table('tenant_users as member')->join('users as owner', 'owner.id', '=', 'member.user_id')->whereColumn('member.tenant_id', 't.id')->where('member.is_owner', true)->orderBy('owner.id')->limit(1)->select('owner.email'), 'owner_email')
            ->selectSub(DB::table('tenant_users as m')->whereColumn('m.tenant_id', 't.id')->selectRaw('COUNT(*)'), 'users_count')
            ->selectSub(DB::table('products as pr')->whereColumn('pr.tenant_id', 't.id')->whereNull('pr.deleted_at')->selectRaw('COUNT(*)'), 'products_count');
        $page = $q->orderByDesc('t.created_at')->orderBy('t.id')->paginate($d['per_page'] ?? 25);
        $page->getCollection()->transform(fn ($r) => $this->decorateClient($r));

        return $page;
    }

    public function client(string $client)
    {
        $r = $this->clientsQuery()->where('t.public_id', $client)->select('t.*', 'i.name as industry', 'i.key as industry_key', 'p.key as plan_key', 'p.name as plan_name', 's.status as subscription_status', 's.ends_at', 's.grace_ends_at')->first();
        abort_unless($r, 404);
        $id = $r->id;
        $r->owners = DB::table('tenant_users as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $id)->where('m.is_owner', true)->get(['u.public_id', 'u.name', 'u.email', 'u.status']);
        $r->branches = DB::table('branches')->where('tenant_id', $id)->whereNull('deleted_at')->get(['public_id', 'name', 'address']);
        $r->warehouses = DB::table('warehouses as w')->join('branches as b', 'b.id', '=', 'w.branch_id')->where('w.tenant_id', $id)->get(['w.public_id', 'w.name', 'b.public_id as branch', 'b.name as branch_name']);
        $r->usage = ['products' => DB::table('products')->where('tenant_id', $id)->whereNull('deleted_at')->count(), 'users' => DB::table('tenant_users')->where('tenant_id', $id)->count(), 'branches' => count($r->branches), 'warehouses' => count($r->warehouses)];
        $settings = json_decode($r->settings ?? '{}', true);
        $r->contact = $settings['client_contact'] ?? [];
        unset($r->id,$r->settings,$r->industry_id);

        return $this->decorateClient($r);
    }

    public function subscriptions(Request $r)
    {
        $d = Filters::read($r);
        $q = $this->clientsQuery()->whereNull('t.deleted_at');
        Filters::client($q, $d);
        Filters::search($q, $d, ['t.name']);
        if (! empty($d['status'])) {
            $q->where('s.status', $d['status']);
        }if (! empty($d['plan'])) {
            $q->where('p.key', $d['plan']);
        }Filters::due($q, $d, 's.ends_at');
        $page = $q->select('t.public_id', 't.name', 'p.key as plan_key', 'p.name as plan_name', 's.status as subscription_status', 's.starts_at', 's.ends_at', 's.grace_ends_at', 's.updated_at')->orderBy('s.ends_at')->orderBy('t.id')->paginate($d['per_page'] ?? 25);
        $page->getCollection()->transform(fn ($r) => $this->decorateClient($r));

        return $page;
    }

    public function events(string $client)
    {
        $id = DB::table('tenants')->where('public_id', $client)->value('id');
        abort_unless($id, 404);

        return DB::table('subscription_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->where('e.tenant_id', $id)->orderByDesc('e.id')->limit(100)->get(['e.action', 'e.reason', 'e.before_state', 'e.after_state', 'e.created_at', 'u.name as actor']);
    }

    public function payments(Request $r)
    {
        $d = Filters::read($r);
        $q = DB::table('subscription_payments as sp')->join('tenants as t', 't.id', '=', 'sp.tenant_id')->join('plans as p', 'p.id', '=', 'sp.plan_id')->join('payment_methods as m', 'm.id', '=', 'sp.payment_method_id')->leftJoin('billing_invoices as b', 'b.payment_id', '=', 'sp.id');
        Filters::client($q, $d);
        Filters::search($q, $d, ['t.name', 'sp.transaction_reference']);
        if (! empty($d['status'])) {
            $q->where('sp.status', $d['status']);
        }if (! empty($d['plan'])) {
            $q->where('p.key', $d['plan']);
        }

        return $q->select('sp.public_id', 't.public_id as client', 't.name as business_name', 'p.name as plan_name', 'sp.currency', 'sp.amount', 'sp.status', 'sp.transaction_reference', 'sp.paid_on', 'sp.created_at', 'sp.expires_at', 'sp.reviewed_at', 'sp.review_note', 'm.name as method', 'b.public_id as invoice', 'b.number as invoice_number')->orderByDesc('sp.id')->paginate($d['per_page'] ?? 25);
    }

    public function payment(string $payment)
    {
        $p = DB::table('subscription_payments')->where('public_id', $payment)->first(['id', 'public_id', 'currency', 'amount', 'status', 'transaction_reference', 'paid_on', 'created_at', 'expires_at', 'reviewed_at', 'review_note']);
        abort_unless($p, 404);
        $p->has_proof = DB::table('payment_proofs')->where('subscription_payment_id', $p->id)->exists();
        $p->events = DB::table('payment_events')->where('subscription_payment_id', $p->id)->orderBy('id')->get(['status', 'note', 'created_at']);
        unset($p->id);

        return $p;
    }

    public function invoices(Request $r)
    {
        $d = Filters::read($r);
        $q = DB::table('billing_invoices as b')->join('tenants as t', 't.id', '=', 'b.tenant_id')->join('plans as p', 'p.id', '=', 'b.plan_id')->leftJoin('subscription_payments as sp', 'sp.id', '=', 'b.payment_id');
        Filters::client($q, $d);
        Filters::search($q, $d, ['b.number', 't.name']);
        if (($d['status'] ?? '') === 'overdue') {
            $q->where('b.status', 'issued')->where('b.due_on', '<', now()->toDateString());
        } elseif (! empty($d['status'])) {
            $q->where('b.status', $d['status']);
        }if (! empty($d['due'])) {
            Filters::due($q, $d, 'b.due_on', true);
            $q->where('b.status', 'issued');
        }

        return $q->select('b.public_id', 'b.number', 'b.status', 'b.currency', 'b.amount', 'b.issued_on', 'b.due_on', 'b.paid_at', 't.public_id as client', 't.name as business_name', 'p.name as plan_name', 'sp.public_id as payment', 'sp.transaction_reference')->orderByDesc('b.id')->paginate($d['per_page'] ?? 25);
    }

    public function users(Request $r)
    {
        $d = Filters::read($r);
        $q = DB::table('users as u');
        Filters::search($q, $d, ['u.name', 'u.email']);
        if (! empty($d['status'])) {
            $q->where('u.status', $d['status']);
        }if (! empty($d['client'])) {
            $q->whereIn('u.id', DB::table('tenant_users as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('t.public_id', $d['client'])->select('m.user_id'));
        }

        return $q->select('u.public_id', 'u.name', 'u.email', 'u.status', 'u.is_superadmin', 'u.email_verified_at', 'u.created_at')->selectSub(DB::table('tenant_users as m')->whereColumn('m.user_id', 'u.id')->selectRaw('COUNT(*)'), 'businesses')->selectSub(DB::table('login_events as l')->whereColumn('l.user_id', 'u.id')->selectRaw('MAX(created_at)'), 'last_login')->orderByDesc('u.id')->paginate($d['per_page'] ?? 25);
    }

    public function user(string $user)
    {
        $u = DB::table('users')->where('public_id', $user)->first(['id', 'public_id', 'name', 'email', 'status', 'is_superadmin', 'email_verified_at', 'created_at']);
        abort_unless($u, 404);
        $u->memberships = DB::table('tenant_users as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('m.user_id', $u->id)->get(['t.public_id', 't.name', 't.status', 'm.is_owner']);
        $u->platform_roles = DB::table('platform_user_roles as a')->join('platform_roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $u->id)->get(['r.public_id', 'r.name']);
        unset($u->id);

        return $u;
    }

    public function audit(Request $r)
    {
        $d = Filters::read($r);
        $q = DB::table('audit_logs as a')->leftJoin('tenants as t', 't.id', '=', 'a.tenant_id')->leftJoin('users as u', 'u.id', '=', 'a.actor_id');
        Filters::client($q, $d);
        Filters::search($q, $d, ['a.action', 'a.subject', 't.name', 'u.name']);
        if (! empty($d['from'])) {
            $q->where('a.created_at', '>=', $d['from']);
        }if (! empty($d['to'])) {
            $q->where('a.created_at', '<', Carbon::parse($d['to'])->addDay());
        }

        return $q->select('a.id', 'a.action', 'a.subject', 'a.metadata', 'a.created_at', 't.name as business_name', 'u.name as actor_name')->orderByDesc('a.id')->paginate($d['per_page'] ?? 25);
    }

    public function leads(Request $r)
    {
        $d = Filters::read($r);
        $q = DB::table('leads');
        Filters::search($q, $d, ['name', 'email', 'message']);
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        }

        return $q->orderByDesc('id')->paginate($d['per_page'] ?? 25);
    }
}
