<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Services\PaymentService;
use App\Services\ProofStorage;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function catalog()
    {
        return ['plans' => Plan::with('prices')->where('active', true)->get(), 'payment_methods' => DB::table('payment_methods')->where('active', true)->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'details' => json_decode($m->details, true)])];
    }

    public function index(TenantContext $c)
    {
        $c->authorize('subscriptions.view');

        $payments = SubscriptionPayment::where('tenant_id', $c->tenant->id)->latest()->limit(50)->get();
        $events = DB::table('payment_events')->whereIn('subscription_payment_id', $payments->pluck('id'))->orderBy('id')->get()->groupBy('subscription_payment_id');
        $proofs = DB::table('payment_proofs')->whereIn('subscription_payment_id', $payments->pluck('id'))->pluck('subscription_payment_id')->flip();

        return $payments->map(function ($p) use ($events, $proofs) {
            $p->setAttribute('events', ($events->get($p->id) ?? collect())->values());
            $p->setAttribute('has_proof', $proofs->has($p->id));

            return $p;
        });
    }

    public function create(Request $r, TenantContext $c, PaymentService $service)
    {
        $c->authorize('subscriptions.manage');
        $data = $r->validate(['plan_key' => 'required|string|exists:plans,key', 'payment_method_id' => 'required|integer|exists:payment_methods,id']);
        $plan = Plan::where('key', $data['plan_key'])->where('active', true)->firstOrFail();
        abort_if($plan->key === 'free', 422, 'The Free plan requires no payment.');
        abort_unless(DB::table('payment_methods')->where('id', $data['payment_method_id'])->where('active', true)->exists(), 422, 'Payment method unavailable.');

        return DB::transaction(function () use ($c, $plan, $data, $service, $r) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            abort_if(SubscriptionPayment::where('tenant_id', $c->tenant->id)->where(fn ($q) => $q->where('status', 'pending_review')->orWhere(fn ($o) => $o->whereIn('status', ['draft', 'needs_information'])->where('expires_at', '>', now())))->exists(), 422, 'You already have an open payment request.');
            $p = SubscriptionPayment::create(['tenant_id' => $c->tenant->id, 'plan_id' => $plan->id, 'payment_method_id' => $data['payment_method_id'], 'submitted_by' => $r->user()->id, 'amount' => $plan->prices()->where('currency', 'PKR')->where('interval', 'month')->firstOrFail()->amount, 'expires_at' => now()->addDays(7)]);
            $service->event($p, 'draft');
            app(\App\Domain\Platform\BillingInvoices::class)->forPayment($p);
            Audit::record('payment.created', $c->tenant->id, $p->public_id);

            return response()->json($p->fresh(), 201);
        });
    }

    private function owned(string $id, TenantContext $c): SubscriptionPayment
    {
        $c->authorize('subscriptions.manage');

        return SubscriptionPayment::where('tenant_id', $c->tenant->id)->where('public_id', $id)->firstOrFail();
    }

    public function proof(Request $r, string $payment, TenantContext $c, PaymentService $service, ProofStorage $storage)
    {
        $p = $this->owned($payment, $c);
        $r->validate(['proof' => 'required|file|mimes:png,jpg,jpeg,webp|max:5120']);
        try {
            DB::transaction(function () use ($p, $r, $service, $storage) {
                $locked = SubscriptionPayment::whereKey($p->id)->lockForUpdate()->firstOrFail();
                $service->assertEditable($locked);
                $storage->store($locked, $r->file('proof'));
                Audit::record('payment.proof_uploaded', $p->tenant_id, $p->public_id);
            });
        } catch (\Throwable $e) {
            $storage->discardUncommitted();
            throw $e;
        }

        return ['message' => 'Screenshot uploaded. Submit the payment for review when ready.'];
    }

    public function submit(Request $r, string $payment, TenantContext $c, PaymentService $service)
    {
        $data = $r->validate(['transaction_reference' => 'required|string|min:4|max:100', 'paid_on' => 'required|date|before_or_equal:today|after_or_equal:'.now()->subDays(30)->toDateString(), 'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/']]);

        return $service->submit($this->owned($payment, $c), $data);
    }

    public function uploadLink(string $payment, TenantContext $c, PaymentService $service)
    {
        $p = $this->owned($payment, $c);
        $service->assertEditable($p);
        $raw = Str::random(64);
        $expires = now()->addMinutes(15);
        DB::table('payment_upload_tokens')->where('subscription_payment_id', $p->id)->whereNull('used_at')->delete();
        DB::table('payment_upload_tokens')->insert(['subscription_payment_id' => $p->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return ['url' => URL::temporarySignedRoute('payment.mobile', $expires, ['token' => $raw]), 'expires_at' => $expires];
    }

    public function download(Request $r, string $payment, TenantContext $c)
    {
        $c->authorize('subscriptions.view');
        $p = SubscriptionPayment::where('tenant_id', $c->tenant->id)->where('public_id', $payment)->firstOrFail();
        $proof = DB::table('payment_proofs')->where('subscription_payment_id', $p->id)->first();
        abort_unless($proof, 404);

        return Storage::disk('local')->download($proof->path, 'payment-proof.png', ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
