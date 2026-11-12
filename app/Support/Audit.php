<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(string $action, ?int $tenantId = null, ?string $subject = null, array $metadata = []): void
    {
        DB::table('audit_logs')->insert(['action' => $action, 'tenant_id' => $tenantId, 'actor_id' => auth()->id(),
            'subject' => $subject, 'metadata' => json_encode($metadata), 'ip' => request()->ip(), 'created_at' => now()]);
    }
}
