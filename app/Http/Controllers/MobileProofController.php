<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Services\PaymentService;
use App\Services\ProofStorage;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileProofController extends Controller
{
    private function token(string $token)
    {
        $t = DB::table('payment_upload_tokens')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($t && ! $t->used_at && now()->lt($t->expires_at), 410, 'This upload link has expired or has already been used. Ask for a new QR code.');

        return $t;
    }

    public function show(string $token)
    {
        $this->token($token);

        return response()->view('payments.mobile')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex');
    }

    public function store(Request $r, string $token, PaymentService $service, ProofStorage $storage)
    {
        $this->token($token);
        $r->validate(['proof' => 'required|file|mimes:png,jpg,jpeg,webp|max:5120']);
        try {
            DB::transaction(function () use ($token, $r, $service, $storage) {
                $t = DB::table('payment_upload_tokens')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
                abort_unless($t && ! $t->used_at && now()->lt($t->expires_at), 410);
                $p = SubscriptionPayment::whereKey($t->subscription_payment_id)->lockForUpdate()->firstOrFail();
                $service->assertEditable($p);
                $storage->store($p, $r->file('proof'));
                DB::table('payment_upload_tokens')->where('id', $t->id)->update(['used_at' => now()]);
                Audit::record('payment.mobile_proof_uploaded', $p->tenant_id, $p->public_id);
            });
        } catch (\Throwable $e) {
            $storage->discardUncommitted();
            throw $e;
        }

        return response()->view('payments.mobile', ['success' => true])->header('Cache-Control', 'no-store');
    }
}
