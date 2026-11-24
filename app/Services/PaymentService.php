<?php

namespace App\Services;

use App\Domain\Platform\BillingInvoices;
use App\Domain\Platform\PlatformAccess;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class PaymentService
{
    public function event(SubscriptionPayment $p, string $status, ?string $note = null): void
    {
        DB::table('payment_events')->insert(['subscription_payment_id' => $p->id, 'status' => $status, 'note' => $note, 'created_at' => now()]);
    }

    public function review(string $publicId, string $decision, ?string $note, int $reviewer): SubscriptionPayment
    {
        abort_unless(in_array($decision, ['approved', 'rejected', 'needs_information']), 422, 'Invalid review decision.');
        app(PlatformAccess::class)->authorize(User::findOrFail($reviewer), 'payments.review');

        return DB::transaction(function () use ($publicId, $decision, $note, $reviewer) {
            $tenantId = SubscriptionPayment::where('public_id', $publicId)->value('tenant_id');
            abort_unless($tenantId, 404);
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $p = SubscriptionPayment::where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if (! in_array($p->status, ['pending_review', 'needs_information'])) {
                throw ValidationException::withMessages(['status' => 'This payment is not awaiting review.']);
            }
            // A submitted request waits for review indefinitely; only customer-side states time out.
            if ($p->status !== 'pending_review' && $p->expires_at->isPast()) {
                throw ValidationException::withMessages(['status' => 'This payment request has expired.']);
            }
            if ($p->submitted_by === $reviewer) {
                throw ValidationException::withMessages(['review' => 'You cannot approve your own payment.']);
            }
            if ($decision === 'approved') {
                $proof = DB::table('payment_proofs')->where('subscription_payment_id', $p->id)->first();
                if ($p->applied_at || ! $proof || ! Storage::disk('local')->exists($proof->path)) {
                    throw ValidationException::withMessages(['proof' => 'A valid payment proof is required.']);
                }
                $s = DB::table('subscriptions')->where('tenant_id', $p->tenant_id)->lockForUpdate()->first();
                $start = now();
                $base = ($s->plan_id === $p->plan_id && $s->status === 'active' && $s->ends_at && now()->lt($s->ends_at)) ? Carbon::parse($s->ends_at) : now();
                $periodStart = $base->copy();
                $periodEnd = $base->copy()->addMonthNoOverflow();
                DB::table('subscriptions')->where('id', $s->id)->update(['plan_id' => $p->plan_id, 'status' => 'active', 'starts_at' => $start, 'ends_at' => $periodEnd, 'grace_ends_at' => null, 'updated_at' => now()]);
                app(BillingInvoices::class)->paid($p, $periodStart, $periodEnd);
                DB::table('subscription_events')->insert(['tenant_id' => $p->tenant_id, 'actor_id' => $reviewer, 'action' => 'payment_approved', 'before_state' => json_encode((array) $s), 'after_state' => json_encode(['plan_id' => $p->plan_id, 'status' => 'active', 'ends_at' => $periodEnd->toIso8601String()]), 'reason' => $note ?? 'Verified manual subscription payment', 'created_at' => now()]);
                $p->applied_at = now();
            }
            // Asking for more information hands the request back to the customer with a fresh window.
            $window = $decision === 'needs_information' && $p->expires_at->lt(now()->addDays(7)) ? ['expires_at' => now()->addDays(7)] : [];
            $p->fill(['status' => $decision, 'review_note' => $note, 'reviewed_by' => $reviewer, 'reviewed_at' => now()] + $window)->save();
            if ($decision === 'rejected') {
                app(BillingInvoices::class)->releaseForPayment($p, 'Linked payment rejected');
            }
            $this->event($p, $decision, $note);
            Audit::record('payment.'.$decision, $p->tenant_id, $p->public_id, ['amount' => $p->amount, 'plan_id' => $p->plan_id]);

            return $p;
        });
    }

    public function submit(SubscriptionPayment $payment, array $data): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $data) {
            $p = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($p);
            if (! DB::table('payment_proofs')->where('subscription_payment_id', $p->id)->exists()) {
                throw ValidationException::withMessages(['proof' => 'Upload your payment screenshot before submitting.']);
            }
            if (bccomp((string) $data['amount'], (string) $p->amount, 2) !== 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must match your quoted plan price.']);
            }
            $reference = trim($data['transaction_reference']);
            $fingerprint = hash('sha256', $p->payment_method_id.'|'.mb_strtolower(preg_replace('/\s+/u', '', $reference)));
            if (SubscriptionPayment::where('reference_fingerprint', $fingerprint)->where('id', '!=', $p->id)->exists()) {
                throw ValidationException::withMessages(['transaction_reference' => 'This transaction reference has already been submitted.']);
            }
            $p->fill(['transaction_reference' => $reference, 'reference_fingerprint' => $fingerprint, 'paid_on' => $data['paid_on'], 'status' => 'pending_review'])->save();
            $this->event($p, 'submitted');
            $this->event($p, 'pending_review');
            Audit::record('payment.submitted', $p->tenant_id, $p->public_id);

            return $p;
        });
    }

    public function assertEditable(SubscriptionPayment $p): void
    {
        if (! in_array($p->status, ['draft', 'needs_information']) || $p->expires_at->isPast()) {
            throw ValidationException::withMessages(['status' => 'This payment cannot be changed or has expired.']);
        }
    }
}
