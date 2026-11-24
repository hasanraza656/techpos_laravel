<?php

namespace App\Domain\Operations;

use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterService
{
    private function present(object $row): object
    {
        foreach (['opening_float', 'expected_cash', 'actual_cash', 'cash_difference', 'amount'] as $key) {
            if (isset($row->$key)) {
                $row->$key = bcadd((string) $row->$key, '0', 2);
            }
        }

        return $row;
    }

    public function open(TenantContext $c, object $branch, array $data): object
    {
        return DB::transaction(function () use ($c, $branch, $data) {
            // Serialize a cashier opening different registers concurrently.
            DB::table('users')->where('id', $c->user->id)->lockForUpdate()->first();
            $register = DB::table('registers')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('public_id', $data['register'])->where('active', true)->lockForUpdate()->first();
            abort_unless($register, 404, 'Register not found.');
            $float = Decimal::input($data['opening_float']);
            $existing = DB::table('register_shifts')->where('open_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->tenant_id === $c->tenant->id && $existing->register_id === $register->id && $existing->user_id === $c->user->id && bccomp($existing->opening_float, $float, 2) === 0, 409, 'Shift key was already used for different details.');

                return $this->present($existing);
            }
            if (DB::table('register_shifts')->where('register_id', $register->id)->whereNull('closed_at')->exists()) {
                Decimal::fail('register', 'This register already has an open shift.');
            }
            if (DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('user_id', $c->user->id)->whereNull('closed_at')->exists()) {
                Decimal::fail('register', 'Close your current shift before opening another.');
            }
            $id = DB::table('register_shifts')->insertGetId(['tenant_id' => $c->tenant->id, 'branch_id' => $branch->id, 'register_id' => $register->id, 'user_id' => $c->user->id, 'public_id' => (string) Str::uuid(), 'opening_float' => $float, 'opened_at' => now(), 'open_key' => $data['idempotency_key']]);
            Audit::record('register.shift_opened', $c->tenant->id, (string) $id);

            return $this->present(DB::table('register_shifts')->find($id));
        }, 3);
    }

    public function expected(int $tenant, int $shift): string
    {
        $s = DB::table('register_shifts')->where('tenant_id', $tenant)->where('id', $shift)->first();
        abort_unless($s, 404);
        // Filter on the full (tenant_id, branch_id, shift_id) / (tenant_id, sale_id) composite
        // keys so these lookups use the FK indexes instead of scanning all tenant history.
        $cash = Decimal::sum(DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.tenant_id', $tenant)->where('s.branch_id', $s->branch_id)->where('s.shift_id', $shift)->where('p.tenant_id', $tenant)->where('p.method', 'cash')->select('p.amount')->cursor()->map(fn ($r) => (string) $r->amount));
        $change = Decimal::sum(DB::table('sales')->where('tenant_id', $tenant)->where('branch_id', $s->branch_id)->where('shift_id', $shift)->select('change_due')->cursor()->map(fn ($r) => (string) $r->change_due));
        $events = Decimal::sum(DB::table('cash_events')->where('tenant_id', $tenant)->where('branch_id', $s->branch_id)->where('shift_id', $shift)->select('amount')->cursor()->map(fn ($r) => (string) $r->amount));

        $refunds = Decimal::sum(DB::table('sale_returns')->where('tenant_id', $tenant)->where('branch_id', $s->branch_id)->where('shift_id', $shift)->select('total')->cursor()->map(fn ($r) => (string) $r->total));

        return bcsub(bcadd(bcadd($s->opening_float, bcsub((string) $cash, (string) $change, 2), 2), (string) $events, 2), $refunds, 2);
    }

    public function close(TenantContext $c, string $uuid, array $data): object
    {
        return DB::transaction(function () use ($c, $uuid, $data) {
            $s = DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('public_id', $uuid)->lockForUpdate()->first();
            abort_unless($s, 404);
            $c->authorize('sales.create', $s->branch_id);
            abort_unless($s->user_id === $c->user->id || $c->allows('register.close', $s->branch_id), 403, 'Only the assigned cashier or an authorized manager can close this shift.');
            $actual = Decimal::input($data['actual_cash']);
            if ($s->closed_at) {
                abort_unless($s->close_key === $data['idempotency_key'] && bccomp($s->actual_cash, $actual, 2) === 0, 409, 'This shift has already been closed.');

                return $this->present($s);
            }
            $expected = $this->expected($c->tenant->id, $s->id);
            DB::table('register_shifts')->where('id', $s->id)->update(['closed_at' => now(), 'actual_cash' => $actual, 'expected_cash' => $expected, 'cash_difference' => bcsub($actual, $expected, 2), 'closing_note' => $data['note'] ?? null, 'close_key' => $data['idempotency_key']]);
            Audit::record('register.shift_closed', $c->tenant->id, $uuid, ['expected' => $expected, 'actual' => $actual]);

            return $this->present(DB::table('register_shifts')->find($s->id));
        }, 3);
    }

    public function cashEvent(TenantContext $c, string $uuid, array $data): object
    {
        return DB::transaction(function () use ($c, $uuid, $data) {
            $s = DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('public_id', $uuid)->lockForUpdate()->first();
            abort_unless($s, 404);
            $c->authorize('register.open_drawer', $s->branch_id);
            abort_unless($s->user_id === $c->user->id || $c->allows('register.close', $s->branch_id), 403);
            $amount = Decimal::input($data['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                Decimal::fail('amount', 'Cash movement must be positive.');
            }$signed = $data['type'] === 'cash_in' ? $amount : bcsub('0', $amount, 2);
            $old = DB::table('cash_events')->where('tenant_id', $c->tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->shift_id === $s->id && $old->type === $data['type'] && bccomp($old->amount, $signed, 2) === 0 && $old->reason === $data['reason'], 409, 'Cash movement key conflicts with an existing event.');

                return $old;
            }
            // Checked after the replay lookup so a retried, already-recorded event is acknowledged.
            abort_if($s->closed_at, 422, 'This shift is closed.');
            $id = DB::table('cash_events')->insertGetId(['public_id' => (string) Str::uuid(), 'tenant_id' => $c->tenant->id, 'branch_id' => $s->branch_id, 'shift_id' => $s->id, 'user_id' => $c->user->id, 'idempotency_key' => $data['idempotency_key'], 'type' => $data['type'], 'amount' => $signed, 'reason' => $data['reason'], 'created_at' => now()]);
            Audit::record('register.cash_event', $c->tenant->id, (string) $id, ['type' => $data['type'], 'amount' => $signed]);

            return DB::table('cash_events')->find($id);
        }, 3);
    }
}
