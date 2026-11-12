<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class DocumentController extends Controller
{
    public function invoice(string $invoice)
    {
        $b = DB::table('billing_invoices')->where('public_id', $invoice)->first();
        abort_unless($b, 404);
        Audit::record('platform.invoice_document_read', $b->tenant_id, $invoice);

        return response()->view('platform.document', ['title' => 'Subscription invoice', 'number' => $b->number, 'currency' => $b->currency, 'total' => bcadd((string) $b->amount, '0', 2), 'status' => $b->status, 'issued' => $b->issued_on, 'due' => $b->due_on, 'seller' => json_decode($b->seller, true), 'client' => json_decode($b->client, true), 'lines' => json_decode($b->lines, true), 'note' => 'Subscription billing document. An unpaid invoice does not activate paid features.']);
    }

    public function sale(string $sale)
    {
        $s = DB::table('sales')->where('public_id', $sale)->first();
        abort_unless($s, 404);
        $t = DB::table('tenants')->find($s->tenant_id);
        Audit::record('platform.client_receipt_read', $t->id, $sale);

        return response()->view('platform.document', ['title' => 'Client sales receipt', 'number' => $s->receipt_number, 'currency' => $s->currency, 'total' => bcadd((string) $s->total, '0', 2), 'status' => 'finalized', 'issued' => $s->finalized_at, 'due' => null, 'seller' => ['name' => $t->name], 'client' => ['name' => 'Recorded sale'], 'lines' => DB::table('sale_lines')->where('tenant_id', $t->id)->where('sale_id', $s->id)->orderBy('position')->get()->map(fn ($l) => ['description' => $l->name, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'total' => $l->total])->all(), 'note' => 'Read-only receipt from immutable finalized sale lines. Returns are separate compensating documents.']);
    }
}
