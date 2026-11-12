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
            $subs = DB::table('subscriptions')->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())->lockForUpdate()->get();
            foreach ($subs as $s) {
                DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'expired', 'updated_at' => now()]);
                Audit::record('subscription.expired', $s->tenant_id);
            }
            $payments = SubscriptionPayment::whereIn('status', ['draft', 'pending_review', 'needs_information'])->where('expires_at', '<=', now())->lockForUpdate()->get();
            foreach ($payments as $p) {
                $p->update(['status' => 'expired']);
                app(PaymentService::class)->event($p, 'expired');
            }
        });
        $this->info('Expiration sweep complete.');

        return self::SUCCESS;
    }
}
