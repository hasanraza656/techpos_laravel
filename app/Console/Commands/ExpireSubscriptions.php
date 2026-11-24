<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPayment;
use App\Services\PaymentService;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireSubscriptions extends Command
{
    protected $signature = 'techpos:expire-subscriptions';

    protected $description = 'Expire elapsed subscriptions and outstanding payment requests without deleting tenant records';

    public function handle(): int
    {
        DB::transaction(function () {
            DB::table('revoked_sessions')->where('expires_at', '<=', now())->delete();
            $subs = DB::table('subscriptions')->whereNotNull('ends_at')->where('ends_at', '<=', now())
                ->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($g) => $g->where('status', 'grace')->where(fn ($e) => $e->whereNull('grace_ends_at')->orWhere('grace_ends_at', '<=', now()))))
                ->lockForUpdate()->get();
            foreach ($subs as $s) {
                DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'expired', 'updated_at' => now()]);
                Audit::record('subscription.expired', $s->tenant_id);
            }
            // Submitted (pending_review) requests never time out; they wait for a platform decision.
            $payments = SubscriptionPayment::whereIn('status', ['draft', 'needs_information'])->where('expires_at', '<=', now())->lockForUpdate()->get();
            foreach ($payments as $p) {
                // Release the unique reference fingerprint so the customer can resubmit the same transfer.
                $p->update(['status' => 'expired', 'reference_fingerprint' => null]);
                app(\App\Domain\Platform\BillingInvoices::class)->releaseForPayment($p, 'Payment request expired');
                app(PaymentService::class)->event($p, 'expired');
            }
        });
        $this->info('Expiration sweep complete.');

        return self::SUCCESS;
    }
}
