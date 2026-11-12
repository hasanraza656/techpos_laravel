<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Services\PaymentService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlatformController extends Controller
{
    public function overview()
    {
        $active = DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('subscriptions.status', 'active')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));

        return ['businesses' => DB::table('tenants')->whereNull('deleted_at')->count(), 'active_paid' => (clone $active)->where('plans.key', '!=', 'free')->count(),
            'pending_payments' => SubscriptionPayment::where('status', 'pending_review')->count(),
            'mrr' => (clone $active)->join('plan_prices', 'plan_prices.plan_id', '=', 'plans.id')->where('plan_prices.interval', 'month')->where('plan_prices.currency', 'PKR')->sum('plan_prices.amount'),
            'industry_distribution' => DB::table('tenants')->join('industries', 'industries.id', '=', 'tenants.industry_id')->whereNull('tenants.deleted_at')->groupBy('industries.name')->selectRaw('industries.name, COUNT(*) AS count')->get()];
    }

    public function payments()
    {
        return SubscriptionPayment::query()->join('tenants', 'tenants.id', '=', 'subscription_payments.tenant_id')->join('plans', 'plans.id', '=', 'subscription_payments.plan_id')
            ->select('subscription_payments.*', 'tenants.name as business_name', 'plans.name as plan_name')->orderByDesc('subscription_payments.id')->paginate(25);
    }

    public function review(Request $r, string $payment, PaymentService $service)
    {
        $data = $r->validate(['decision' => 'required|in:approved,rejected,needs_information', 'note' => 'nullable|required_unless:decision,approved|string|max:2000', 'password' => 'required|current_password:web']);

        return $service->review($payment, $data['decision'], $data['note'] ?? null, $r->user()->id);
    }

    public function proof(string $payment)
    {
        $p = SubscriptionPayment::where('public_id', $payment)->firstOrFail();
        $proof = DB::table('payment_proofs')->where('subscription_payment_id', $p->id)->first();
        abort_unless($proof && Storage::disk('local')->exists($proof->path), 404, 'The proof file is unavailable. Request a new copy before approval.');
        Audit::record('platform.proof_viewed', $p->tenant_id, $p->public_id);

        return Storage::disk('local')->download($proof->path, 'payment-proof.png', ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    public function tenants()
    {
        return DB::table('tenants')->whereNull('deleted_at')->select('public_id', 'name', 'status', 'created_at', 'industry_id')->orderByDesc('id')->paginate(25);
    }

    public function plans()
    {
        return Plan::with('prices')->get();
    }

    public function updatePlan(Request $r, string $plan)
    {
        $data = $r->validate(['password' => 'required|current_password:web', 'reason' => 'required|string|min:5|max:1000', 'name' => 'required|string|max:100', 'description' => 'required|string|max:1000', 'amount' => 'required|numeric|min:0|max:99999999',
            'limits' => 'required|array:businesses,branches,users,cashiers,active_skus,history_days', 'limits.businesses' => 'required|integer|min:1|max:100',
            'limits.branches' => 'required|integer|min:1|max:10000', 'limits.users' => 'required|integer|min:1|max:100000', 'limits.cashiers' => 'required|integer|min:1|max:100000', 'limits.active_skus' => 'required|integer|min:1|max:10000000', 'limits.history_days' => 'required|integer|min:1|max:36500']);
        $p = Plan::where('key', $plan)->firstOrFail();
        abort_if($p->key === 'free' && (float) $data['amount'] !== 0.0, 422, 'Free pricing must remain zero.');
        DB::transaction(function () use ($p, $data) {
            $p->update(['name' => $data['name'], 'description' => $data['description'], 'limits' => $data['limits']]);
            $p->prices()->where('currency', 'PKR')->where('interval', 'month')->update(['amount' => $data['amount'], 'updated_at' => now()]);
            Audit::record('platform.plan_updated', null, $p->key, ['limits' => $data['limits'], 'amount' => $data['amount'], 'reason' => $data['reason']]);
        });

        return $p->load('prices');
    }

    public function audit()
    {
        return DB::table('audit_logs')->orderByDesc('id')->paginate(50);
    }

    public function leads()
    {
        return DB::table('leads')->orderByDesc('id')->paginate(25);
    }

    public function methods()
    {
        return DB::table('payment_methods')->get()->map(function ($m) {
            $m->details = json_decode($m->details, true);

            return $m;
        });
    }

    public function updateMethod(Request $r, int $method)
    {
        $data = $r->validate(['password' => 'required|current_password:web', 'reason' => 'required|string|min:5|max:1000', 'name' => 'required|string|max:100', 'active' => 'required|boolean', 'details' => 'required|array:bank,account_number,account_holder', 'details.account_number' => 'required|string|max:100', 'details.bank' => 'nullable|string|max:100', 'details.account_holder' => 'nullable|string|max:100']);
        abort_unless(DB::table('payment_methods')->where('id', $method)->exists(), 404);
        DB::table('payment_methods')->where('id', $method)->update(['name' => $data['name'], 'active' => $data['active'], 'details' => json_encode($data['details']), 'updated_at' => now()]);
        Audit::record('platform.payment_method_updated', null, (string) $method, ['reason' => $data['reason']]);

        return ['message' => 'Payment instructions updated.'];
    }
}
