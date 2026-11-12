<?php

namespace App\Domain\Platform;

use App\Models\SubscriptionPayment;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillingInvoices
{
    public function issue(int $tenantId, int $planId, string $requestKey, string $dueOn, ?SubscriptionPayment $payment = null): object
    {
        $old = DB::table('billing_invoices')->where('request_key', $requestKey)->first();
        if ($old) {
            return $old;
        }
        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
        $plan = DB::table('plans')->where('id', $planId)->first();
        abort_unless($tenant && $plan && $plan->key !== 'free', 422, 'Choose a paid plan for a billing invoice.');
        $price = $payment ? $payment->amount : DB::table('plan_prices')->where('plan_id', $planId)->where('currency', 'PKR')->where('interval', 'month')->value('amount');
        abort_unless($price !== null && bccomp((string) $price, '0', 2) > 0, 422, 'This plan has no positive PKR monthly price.');
        $profile = DB::table('platform_settings')->where('key', 'billing_profile')->value('value');
        $seller = $profile ? json_decode($profile, true) : ['name' => 'Tech POS', 'email' => '', 'address' => '', 'registration_number' => ''];
        $settings = json_decode($tenant->settings ?? '{}', true);
        $contact = $settings['client_contact'] ?? [];
        $setup = json_decode(DB::table('onboarding_progress')->where('tenant_id', $tenantId)->value('data') ?? '{}', true);
        $uuid = (string) Str::uuid();
        $paid = $payment?->status === 'approved';
        $id = DB::table('billing_invoices')->insertGetId(['public_id' => $uuid, 'number' => 'TP-SUB-'.now()->format('Ym').'-'.strtoupper(substr(str_replace('-', '', $uuid), 0, 12)),
            'tenant_id' => $tenantId, 'plan_id' => $planId, 'payment_id' => $payment?->id, 'request_key' => $requestKey, 'status' => $paid ? 'paid' : 'issued', 'currency' => 'PKR', 'amount' => bcadd((string) $price, '0', 2),
            'seller' => json_encode($seller), 'client' => json_encode(['name' => $tenant->name, 'email' => $contact['email'] ?? '', 'phone' => $contact['phone'] ?? ($setup['phone'] ?? ''), 'address' => $contact['address'] ?? ($setup['address'] ?? '')]),
            'lines' => json_encode([['description' => $plan->name.' subscription (one month)', 'quantity' => '1', 'unit_price' => bcadd((string) $price, '0', 2), 'total' => bcadd((string) $price, '0', 2)]]),
            'issued_on' => $payment?->created_at?->toDateString() ?? now()->toDateString(), 'due_on' => $dueOn, 'paid_at' => $paid ? ($payment->reviewed_at ?? $payment->applied_at) : null, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('billing.invoice_issued', $tenantId, $uuid, ['source_payment' => $payment?->public_id]);

        return DB::table('billing_invoices')->find($id);
    }

    public function forPayment(SubscriptionPayment $p): object
    {
        $old = DB::table('billing_invoices')->where('payment_id', $p->id)->lockForUpdate()->first();
        if ($old) {
            return $old;
        }
        $open = DB::table('billing_invoices')->where('tenant_id', $p->tenant_id)->where('plan_id', $p->plan_id)->where('amount', $p->amount)->where('status', 'issued')->whereNull('payment_id')->orderBy('id')->lockForUpdate()->first();
        if ($open) {
            DB::table('billing_invoices')->where('id', $open->id)->update(['payment_id' => $p->id, 'updated_at' => now()]);

            return DB::table('billing_invoices')->find($open->id);
        }

        return $this->issue($p->tenant_id, $p->plan_id, $p->public_id, $p->expires_at->toDateString(), $p);
    }

    public function paid(SubscriptionPayment $p, Carbon $start, Carbon $end): void
    {
        $invoice = $this->forPayment($p);
        abort_if($invoice->status === 'void', 422, 'The linked invoice was voided; issue a valid invoice before approval.');
        DB::table('billing_invoices')->where('id', $invoice->id)->update(['status' => 'paid', 'paid_at' => now(), 'period_start' => $start, 'period_end' => $end, 'updated_at' => now()]);
        Audit::record('billing.invoice_paid', $p->tenant_id, $invoice->public_id, ['payment' => $p->public_id]);
    }
}
