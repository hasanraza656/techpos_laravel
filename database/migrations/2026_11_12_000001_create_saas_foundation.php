<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->uuid('public_id')->nullable()->unique();
            $t->boolean('is_superadmin')->default(false);
        });
        Schema::create('industries', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->text('description');
            $t->json('template');
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('name');
            $t->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();
            $t->string('country', 2)->default('PK');
            $t->string('currency', 3)->default('PKR');
            $t->string('timezone')->default('Asia/Karachi');
            $t->string('locale')->default('en');
            $t->string('status')->default('active')->index();
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tenant_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('is_owner')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id']);
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('address')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('branch_id');
            $t->string('name');
            $t->timestamps();
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('module');
            $t->string('name');
            $t->text('description');
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('user_role_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->cascadeOnDelete();
            $t->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $t->index(['tenant_id', 'user_id']);
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->text('description');
            $t->boolean('active')->default(true);
            $t->json('limits');
            $t->timestamps();
        });
        Schema::create('plan_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained();
            $t->string('currency', 3)->default('PKR');
            $t->string('interval')->default('month');
            $t->decimal('amount', 12, 2);
            $t->timestamps();
            $t->unique(['plan_id', 'currency', 'interval']);
        });
        Schema::create('features', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->string('category');
            $t->text('description');
            $t->json('dependencies');
            $t->boolean('requires_configuration')->default(false);
        });
        Schema::create('feature_entitlements', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $t->primary(['plan_id', 'feature_id']);
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained();
            $t->string('status')->default('active');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable()->index();
            $t->timestamp('grace_ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tenant_feature_overrides', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $t->boolean('enabled');
            $t->timestamps();
            $t->primary(['tenant_id', 'feature_id']);
        });
        Schema::create('onboarding_progress', function (Blueprint $t) {
            $t->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('step')->default(1);
            $t->json('data');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('label_translations', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('locale', 10);
            $t->string('section');
            $t->string('value');
            $t->string('review_status')->default('reviewed');
            $t->timestamps();
            $t->unique(['key', 'locale']);
        });
        Schema::create('label_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('key');
            $t->string('locale', 10);
            $t->string('value');
            $t->timestamps();
            $t->unique(['tenant_id', 'key', 'locale']);
        });
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->json('details');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('subscription_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('plan_id')->constrained();
            $t->foreignId('payment_method_id')->constrained();
            $t->foreignId('submitted_by')->constrained('users');
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3)->default('PKR');
            $t->string('transaction_reference')->nullable();
            $t->string('reference_fingerprint')->nullable()->unique();
            $t->date('paid_on')->nullable();
            $t->string('status')->default('draft')->index();
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->dateTime('expires_at');
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('payment_proofs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_payment_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('mime');
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->timestamps();
        });
        Schema::create('payment_upload_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_payment_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_payment_id')->constrained()->cascadeOnDelete();
            $t->string('status');
            $t->text('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action')->index();
            $t->string('subject')->nullable();
            $t->json('metadata')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('device_identifier');
            $t->string('name');
            $t->unsignedInteger('schema_version')->default(1);
            $t->dateTime('last_seen_at');
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id', 'device_identifier']);
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email');
            $t->string('type');
            $t->text('message')->nullable();
            $t->boolean('newsletter_consent')->default(false);
            $t->json('attribution')->nullable();
            $t->string('status')->default('new');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['leads', 'jobs', 'sessions', 'devices', 'audit_logs', 'payment_events', 'payment_upload_tokens', 'payment_proofs', 'subscription_payments', 'payment_methods', 'label_overrides', 'label_translations', 'onboarding_progress', 'tenant_feature_overrides', 'subscriptions', 'feature_entitlements', 'features', 'plan_prices', 'plans', 'user_role_assignments', 'role_permissions', 'roles', 'permissions', 'warehouses', 'branches', 'tenant_users', 'tenants', 'industries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['public_id', 'is_superadmin']);
        });
    }
};
