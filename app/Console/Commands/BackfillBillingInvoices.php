<?php
namespace App\Console\Commands;
use App\Domain\Platform\BillingInvoices;
use App\Models\SubscriptionPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
final class BackfillBillingInvoices extends Command
{
    protected $signature='techpos:backfill-billing-invoices';
    protected $description='Create missing snapshot billing documents from existing payment records without changing entitlements or payments';
    public function handle():int{
        $count=0;
        SubscriptionPayment::whereNotExists(fn($q)=>$q->selectRaw('1')->from('billing_invoices')->whereColumn('payment_id','subscription_payments.id'))->orderBy('id')->chunkById(100,function($rows)use(&$count){foreach($rows as $p){DB::transaction(function()use($p){DB::table('tenants')->where('id',$p->tenant_id)->lockForUpdate()->first();app(BillingInvoices::class)->forPayment($p);});$count++;}});
        $this->info($count.' missing billing documents created.');return self::SUCCESS;
    }
}
