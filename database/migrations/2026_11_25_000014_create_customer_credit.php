<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer credit (udhaar / khata). Purely additive: a cached balance on customers, an
 * append-only signed ledger, the khata share of returns, the feature and its permissions.
 * Catalog rows are inserted idempotently so existing databases gain them without a reseed.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'customers.credit_sell' => ['Sell on credit', 'Record credit (udhaar) tenders on sales within the assigned branch.'],
        'customers.collect' => ['Collect customer payments', 'Receive khata payments and view customer statements within the assigned branch.'],
        'customers.credit_manage' => ['Manage customer credit', 'Set credit limits, record opening balances and adjustments, and override credit limits.'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'balance')) {
            Schema::table('customers', fn (Blueprint $t) => $t->decimal('balance', 14, 2)->default(0));
        }
        if (! Schema::hasColumn('sale_returns', 'credit_amount')) {
            // Adding a defaulted column does not fire the UPDATE immutability triggers.
            Schema::table('sale_returns', fn (Blueprint $t) => $t->decimal('credit_amount', 14, 2)->default(0));
        }
        Schema::create('customer_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('shift_id')->nullable();
            $t->string('type', 30);
            $t->decimal('amount', 14, 2);
            $t->decimal('balance_after', 14, 2);
            $t->string('method', 30)->nullable();
            $t->string('reference', 120)->nullable();
            $t->unsignedBigInteger('sale_id')->nullable();
            $t->unsignedBigInteger('return_id')->nullable();
            $t->string('note', 500)->nullable();
            $t->string('override_reason', 500)->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'customer_id', 'id'], 'customer_ledger_statement');
            $t->index(['tenant_id', 'branch_id', 'created_at'], 'customer_ledger_branch_window');
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $t->foreign(['tenant_id', 'branch_id', 'shift_id'])->references(['tenant_id', 'branch_id', 'id'])->on('register_shifts');
            $t->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales');
            $t->foreign(['tenant_id', 'return_id'])->references(['tenant_id', 'id'])->on('sale_returns');
        });
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'customer_ledger_immutable_'.strtolower($operation);
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON customer_ledger BEGIN SELECT RAISE(ABORT, 'Customer ledger history is immutable'); END");
            } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON customer_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer ledger history is immutable'");
            }
        }
        foreach (self::PERMISSIONS as $key => [$name, $description]) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'module' => 'customers', 'name' => $name, 'description' => $description]);
        }
        DB::table('features')->insertOrIgnore(['key' => 'customer_credit', 'name' => 'Customer credit (khata)', 'category' => 'Customers', 'description' => 'Credit (udhaar) sales, customer payments, statements and credit limits.', 'dependencies' => json_encode(['customers']), 'requires_configuration' => false]);
        $feature = DB::table('features')->where('key', 'customer_credit')->value('id');
        // On a fresh install plans do not exist yet; the seeder entitles them when it creates them.
        foreach (DB::table('plans')->whereIn('key', ['free', 'standard', 'premium'])->pluck('id') as $plan) {
            DB::table('feature_entitlements')->insertOrIgnore(['plan_id' => $plan, 'feature_id' => $feature]);
        }
    }

    public function down(): void
    {
        // Catalog rows: entitlements, overrides and role grants cascade with them.
        DB::table('features')->where('key', 'customer_credit')->delete();
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
        // Dropping the table drops its immutability triggers too.
        Schema::dropIfExists('customer_ledger');
        if (Schema::hasColumn('sale_returns', 'credit_amount')) {
            Schema::table('sale_returns', fn (Blueprint $t) => $t->dropColumn('credit_amount'));
        }
        if (Schema::hasColumn('customers', 'balance')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('balance'));
        }
    }
};
