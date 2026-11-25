<?php

namespace App\Domain\Operations;

use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns every customer_ledger write (udhaar / khata). Callers run inside a transaction; the
 * customer row is locked before its cached balance is read, so balance_after is always the
 * running sum of the ledger and the cache never drifts from it.
 */
final class CustomerCredit
{
    public const PERMISSIONS = [
        'customers.credit_sell' => ['Sell on credit', 'Record credit (udhaar) tenders on sales within the assigned branch.'],
        'customers.collect' => ['Collect customer payments', 'Receive khata payments and view customer statements within the assigned branch.'],
        'customers.credit_manage' => ['Manage customer credit', 'Set credit limits, record opening balances and adjustments, and override credit limits.'],
    ];

    private const MAX = '999999999999.99';

    public static function hash(array $payload): string
    {
        $canonical = function (array $v) use (&$canonical): array {
            if (! array_is_list($v)) {
                ksort($v);
            }
            foreach ($v as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $v;
        };

        return hash('sha256', json_encode($canonical($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }

    public function lock(int $tenantId, int $customerId): object
    {
        $customer = DB::table('customers')->where('tenant_id', $tenantId)->where('id', $customerId)->lockForUpdate()->first();
        abort_unless($customer, 404, 'Customer not found.');

        return $customer;
    }

    private function find(TenantContext $c, string $uuid): object
    {
        // Archived customers keep their khata: statements, collections and corrections still work.
        $customer = DB::table('customers')->where('tenant_id', $c->tenant->id)->where('public_id', $uuid)->first();
        abort_unless($customer, 404, 'Customer not found.');

        return $customer;
    }

    /** Validates a credit tender against the locked customer. Returns true when a manager override was used. */
    public function assertChargeable(TenantContext $c, object $customer, int $branchId, string $amount, ?string $overrideReason): bool
    {
        $limit = self::money($customer->credit_limit);
        if (bccomp($limit, '0', 2) <= 0) {
            Decimal::fail('payments', 'Credit is not enabled for this customer.');
        }
        $after = bcadd(self::money($customer->balance), $amount, 2);
        if (bccomp($after, self::MAX, 2) > 0) {
            Decimal::fail('payments', 'Customer balance exceeds the supported limit.');
        }
        if (bccomp($after, $limit, 2) <= 0) {
            return false;
        }
        $available = $this->available($customer);
        if ($overrideReason === null || trim($overrideReason) === '') {
            Decimal::fail('credit_limit', "Credit limit exceeded. Available credit: $available.");
        }
        if (! $c->allows('customers.credit_manage', $branchId)) {
            Decimal::fail('credit_limit', "Credit limit exceeded. Available credit: $available. Only a user who manages customer credit can override the limit.");
        }

        return true;
    }

    public function available(object $customer): string
    {
        $left = bcsub(self::money($customer->credit_limit), self::money($customer->balance), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /** Appends one entry to the locked customer's ledger and refreshes the cached balance. */
    public function append(TenantContext $c, object $customer, string $type, string $amount, string $key, string $hash, array $extra = []): object
    {
        $after = bcadd(self::money($customer->balance), $amount, 2);
        if (bccomp($after, '0', 2) < 0) {
            Decimal::fail('amount', 'The customer balance cannot become negative.');
        }
        if (bccomp($after, self::MAX, 2) > 0) {
            Decimal::fail('amount', 'Customer balance exceeds the supported limit.');
        }
        $row = array_merge(['branch_id' => null, 'shift_id' => null, 'method' => null, 'reference' => null, 'sale_id' => null, 'return_id' => null, 'note' => null, 'override_reason' => null], $extra, [
            'tenant_id' => $c->tenant->id, 'public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => $type, 'amount' => $amount,
            'balance_after' => $after, 'actor_id' => $c->user->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now(),
        ]);
        $id = DB::table('customer_ledger')->insertGetId($row);
        DB::table('customers')->where('id', $customer->id)->update(['balance' => $after]);
        $customer->balance = $after;

        return DB::table('customer_ledger')->find($id);
    }

    /** Previous entry for a key: null when unused, the row on an exact retry, 409 on conflicting reuse. */
    private function replay(TenantContext $c, string $key, string $hash): ?object
    {
        $old = DB::table('customer_ledger')->where('tenant_id', $c->tenant->id)->where('idempotency_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->payload_hash, $hash) && (int) $old->actor_id === $c->user->id, 409, 'Idempotency key is bound to a different customer ledger entry.');
        }

        return $old;
    }

    /** Runs a ledger write; a concurrent insert of the same key is resolved as a replay or 409. */
    private function once(TenantContext $c, string $key, string $hash, callable $write, callable $present): array
    {
        try {
            return DB::transaction($write, 3);
        } catch (QueryException $e) {
            $old = $this->replay($c, $key, $hash);
            if ($old) {
                return $present($old);
            }
            throw $e;
        }
    }

    public function collect(TenantContext $c, string $uuid, array $d): array
    {
        $branch = app(OperationScope::class)->branch($d['branch'], 'customers.collect');
        app(Entitlements::class)->authorize($c->tenant->id, 'customer_credit');
        $customer = $this->find($c, $uuid);
        $payload = $d;
        unset($payload['idempotency_key']);
        $hash = self::hash(['kind' => 'payment', 'customer' => $customer->public_id, 'payload' => $payload]);
        $key = $d['idempotency_key'];

        return $this->once($c, $key, $hash, function () use ($c, $d, $branch, $customer, $hash, $key) {
            $shift = null;
            if (! empty($d['shift'])) {
                // Shift is locked before the customer, the same order sales and returns use.
                $shift = DB::table('register_shifts')->where('tenant_id', $c->tenant->id)->where('branch_id', $branch->id)->where('public_id', $d['shift'])->lockForUpdate()->first();
                abort_unless($shift, 404, 'Shift not found.');
            }
            $customer = $this->lock($c->tenant->id, $customer->id);
            if ($old = $this->replay($c, $key, $hash)) {
                return $this->paymentDocument($c, $old);
            }
            if ($d['method'] === 'cash') {
                if (! $shift) {
                    Decimal::fail('shift', 'Open your shift to receive cash; it goes into that drawer.');
                }
            } elseif (empty($d['reference'])) {
                Decimal::fail('reference', 'Bank and card payments need a reference.');
            }
            if ($shift) {
                if ($shift->closed_at) {
                    Decimal::fail('shift', 'This shift is closed.');
                }
                abort_unless($shift->user_id === $c->user->id, 403, 'Payments must use your own assigned shift.');
            }
            $amount = Decimal::input($d['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                Decimal::fail('amount', 'Payment amount must be positive.');
            }
            $balance = self::money($customer->balance);
            if (bccomp($amount, $balance, 2) > 0) {
                Decimal::fail('amount', "Payment cannot exceed the customer's balance of $balance.");
            }
            $row = $this->append($c, $customer, 'payment', bcsub('0', $amount, 2), $key, $hash, [
                'branch_id' => $branch->id, 'shift_id' => $shift?->id, 'method' => $d['method'], 'reference' => $d['reference'] ?? null, 'note' => $d['note'] ?? null,
            ]);
            Audit::record('customer.payment_received', $c->tenant->id, $row->public_id, ['customer' => $customer->public_id, 'amount' => $amount, 'method' => $d['method'], 'branch' => $branch->public_id]);

            return $this->paymentDocument($c, $row);
        }, fn ($old) => $this->paymentDocument($c, $old));
    }

    public function paymentDocument(TenantContext $c, object $row): array
    {
        $customer = DB::table('customers')->where('tenant_id', $c->tenant->id)->where('id', $row->customer_id)->first(['public_id', 'name', 'phone']);
        $after = self::money($row->balance_after);
        $amount = bcsub('0', self::money($row->amount), 2);
        $created = Carbon::parse($row->created_at, 'UTC');

        return ['payment' => [
            'public_id' => $row->public_id, 'amount' => $amount, 'method' => $row->method, 'reference' => $row->reference, 'note' => $row->note,
            'created_at' => $created->toIso8601String(), 'balance_before' => bcadd($after, $amount, 2), 'balance_after' => $after,
            'customer' => ['public_id' => $customer->public_id, 'name' => $customer->name, 'phone' => $customer->phone],
            'branch' => $row->branch_id ? DB::table('branches')->where('id', $row->branch_id)->value('name') : null,
            // Date part follows the business day (tenant timezone), like sale receipts.
            'receipt_number' => 'TP-PAY-'.$created->copy()->setTimezone($c->tenant->timezone ?: 'UTC')->format('ymd').'-'.strtoupper(str_replace('-', '', $row->public_id)),
        ]];
    }

    public function adjust(TenantContext $c, string $uuid, array $d): array
    {
        abort_unless($c->allowsInAnyBranch('customers.credit_manage'), 403, 'You do not have permission for this action.');
        app(Entitlements::class)->authorize($c->tenant->id, 'customer_credit');
        $customer = $this->find($c, $uuid);
        $payload = $d;
        unset($payload['idempotency_key']);
        $hash = self::hash(['kind' => 'adjustment', 'customer' => $customer->public_id, 'payload' => $payload]);
        $key = $d['idempotency_key'];
        $present = fn ($row) => ['entry' => $this->entries($c, collect([$row]))[0], 'balance' => self::money($row->balance_after)];

        return $this->once($c, $key, $hash, function () use ($c, $d, $customer, $hash, $key, $present) {
            $customer = $this->lock($c->tenant->id, $customer->id);
            if ($old = $this->replay($c, $key, $hash)) {
                return $present($old);
            }
            $note = isset($d['note']) ? trim($d['note']) : null;
            $amount = Decimal::input($d['amount'], 2, true);
            if ($d['type'] === 'opening_balance') {
                if (bccomp($amount, '0', 2) <= 0) {
                    Decimal::fail('amount', 'Opening balance must be positive.');
                }
                if (DB::table('customer_ledger')->where('tenant_id', $c->tenant->id)->where('customer_id', $customer->id)->exists()) {
                    Decimal::fail('type', 'An opening balance can only be recorded before any other khata entry.');
                }
            } else {
                if (bccomp($amount, '0', 2) === 0) {
                    Decimal::fail('amount', 'Adjustment amount must not be zero.');
                }
                if ($note === null || mb_strlen($note) < 5) {
                    Decimal::fail('note', 'Explain the adjustment in at least 5 characters.');
                }
                if (bccomp(bcadd(self::money($customer->balance), $amount, 2), '0', 2) < 0) {
                    Decimal::fail('amount', 'This adjustment would make the balance negative. Current balance: '.self::money($customer->balance).'.');
                }
            }
            $row = $this->append($c, $customer, $d['type'], $amount, $key, $hash, ['note' => $note === '' ? null : $note]);
            Audit::record('customer.ledger_'.$d['type'], $c->tenant->id, $row->public_id, ['customer' => $customer->public_id, 'amount' => $amount]);

            return $present($row);
        }, $present);
    }

    public function statement(TenantContext $c, string $uuid, array $d): array
    {
        abort_unless($c->allowsInAnyBranch('customers.collect') || $c->allowsInAnyBranch('customers.credit_manage'), 403, 'You do not have permission for this action.');
        app(Entitlements::class)->authorize($c->tenant->id, 'customers');
        $customer = $this->find($c, $uuid);
        $tz = $c->tenant->timezone ?: 'UTC';
        $to = isset($d['to']) ? CarbonImmutable::parse($d['to'], $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
        $from = isset($d['from']) ? CarbonImmutable::parse($d['from'], $tz)->startOfDay() : $to->subDays(29);
        if ($to->lt($from) || $from->diffInDays($to) > 365) {
            Decimal::fail('to', 'Choose an ordered range of at most 366 days.');
        }
        $start = $from->utc();
        $until = $to->addDay()->utc();
        $ledger = fn () => DB::table('customer_ledger')->where('tenant_id', $c->tenant->id)->where('customer_id', $customer->id);
        $opening = $ledger()->where('created_at', '<', $start)->orderByDesc('id')->value('balance_after');
        $closing = $ledger()->where('created_at', '<', $until)->orderByDesc('id')->value('balance_after');
        $page = $ledger()->where('created_at', '>=', $start)->where('created_at', '<', $until)->orderBy('id')->paginate(50, ['*'], 'page', (int) ($d['page'] ?? 1));

        return [
            'customer' => ['public_id' => $customer->public_id, 'name' => $customer->name, 'phone' => $customer->phone, 'balance' => self::money($customer->balance), 'credit_limit' => self::money($customer->credit_limit), 'available' => $this->available($customer)],
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'opening_balance' => self::money($opening ?? '0'), 'closing_balance' => self::money($closing ?? '0'),
            'entries' => $this->entries($c, collect($page->items())),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ];
    }

    /** Statement entry shape, with related names loaded once per page. */
    public function entries(TenantContext $c, $rows): array
    {
        $sales = DB::table('sales')->where('tenant_id', $c->tenant->id)->whereIn('id', $rows->pluck('sale_id')->filter()->unique())->get(['id', 'public_id', 'receipt_number'])->keyBy('id');
        $returns = DB::table('sale_returns')->where('tenant_id', $c->tenant->id)->whereIn('id', $rows->pluck('return_id')->filter()->unique())->pluck('public_id', 'id');
        $branches = DB::table('branches')->where('tenant_id', $c->tenant->id)->whereIn('id', $rows->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $actors = DB::table('users')->whereIn('id', $rows->pluck('actor_id')->unique())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'public_id' => $r->public_id, 'type' => $r->type, 'amount' => self::money($r->amount), 'balance_after' => self::money($r->balance_after),
            'method' => $r->method, 'reference' => $r->reference, 'note' => $r->note, 'override_reason' => $r->override_reason,
            'sale' => $r->sale_id && $sales->has($r->sale_id) ? ['public_id' => $sales[$r->sale_id]->public_id, 'receipt_number' => $sales[$r->sale_id]->receipt_number] : null,
            'return' => $r->return_id && $returns->has($r->return_id) ? ['public_id' => $returns[$r->return_id]] : null,
            'branch' => $r->branch_id ? ($branches[$r->branch_id] ?? null) : null,
            'actor' => $actors[$r->actor_id] ?? null,
            'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ])->values()->all();
    }

    /** Khata share already returned for a sale, and the credit it was charged. */
    public function saleCredit(int $tenantId, int $saleId): array
    {
        $charged = Decimal::sum(DB::table('sale_payments')->where('tenant_id', $tenantId)->where('sale_id', $saleId)->where('method', 'credit')->pluck('amount')->map(fn ($v) => (string) $v));
        $returned = Decimal::sum(DB::table('sale_returns')->where('tenant_id', $tenantId)->where('sale_id', $saleId)->pluck('credit_amount')->map(fn ($v) => (string) $v));

        return [$charged, $returned];
    }
}
