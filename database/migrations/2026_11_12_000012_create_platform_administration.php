<?php

use App\Domain\Platform\PlatformAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('status', 20)->default('active')->index();
            $t->timestamp('archived_at')->nullable();
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->text('platform_notes')->nullable();
        });
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->string('status_before_suspension', 20)->nullable();
        });
        Schema::create('platform_roles', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('name', 100)->unique();
            $t->text('description')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('platform_role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained('platform_roles')->cascadeOnDelete();
            $t->string('permission', 80);
            $t->primary(['role_id', 'permission']);
        });
        Schema::create('platform_user_roles', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained('platform_roles')->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });
        Schema::create('platform_commands', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action', 80);
            $t->string('payload_hash', 64);
            $t->json('result');
            $t->timestamps();
        });
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->json('value');
            $t->timestamps();
        });
        Schema::create('subscription_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action', 60);
            $t->json('before_state')->nullable();
            $t->json('after_state');
            $t->string('reason', 1000);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('number', 60)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('plan_id')->constrained();
            $t->foreignId('payment_id')->nullable()->unique()->constrained('subscription_payments');
            $t->uuid('request_key')->unique();
            $t->string('status', 20)->default('issued')->index();
            $t->string('currency', 3)->default('PKR');
            $t->decimal('amount', 12, 2);
            $t->json('seller');
            $t->json('client');
            $t->json('lines');
            $t->date('issued_on');
            $t->date('due_on')->index();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'due_on']);
        });
        foreach (PlatformAccess::TEMPLATES as $name => $permissions) {
            $id = DB::table('platform_roles')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => $name, 'description' => 'Default platform role template', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($permissions as $key) {
                DB::table('platform_role_permissions')->insert(['role_id' => $id, 'permission' => $key]);
            }
        }
        $columns = ['public_id', 'number', 'tenant_id', 'plan_id', 'request_key', 'currency', 'amount', 'seller', 'client', 'lines', 'issued_on', 'due_on'];
        if (DB::getDriverName() === 'sqlite') {
            $condition = implode(' OR ', array_map(fn ($c) => "OLD.$c IS NOT NEW.$c", $columns));
            DB::unprepared("CREATE TRIGGER billing_invoice_immutable BEFORE UPDATE ON billing_invoices WHEN $condition BEGIN SELECT RAISE(ABORT, 'Issued invoice details are immutable'); END");
            DB::unprepared("CREATE TRIGGER billing_invoice_no_delete BEFORE DELETE ON billing_invoices BEGIN SELECT RAISE(ABORT, 'Invoice history cannot be deleted'); END");
        } else {
            $condition = implode(' OR ', array_map(fn ($c) => "NOT (OLD.$c <=> NEW.$c)", $columns));
            DB::unprepared("CREATE TRIGGER billing_invoice_immutable BEFORE UPDATE ON billing_invoices FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice details are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER billing_invoice_no_delete BEFORE DELETE ON billing_invoices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice history cannot be deleted'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_invoices') && DB::table('billing_invoices')->exists()) {
            throw new RuntimeException('Issued invoices exist. Preserve billing history instead of rolling this migration back.');
        }
        foreach (['billing_invoices', 'subscription_events', 'platform_settings', 'platform_commands', 'platform_user_roles', 'platform_role_permissions', 'platform_roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('status_before_suspension'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('platform_notes'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['status', 'archived_at']));
    }
};
