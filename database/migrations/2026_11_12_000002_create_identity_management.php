<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invited_by')->constrained('users');
            $t->string('email', 254);
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('token_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles');
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $t->index(['tenant_id', 'email']);
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->string('session_hash', 64)->nullable()->index();
            $t->timestamp('revoked_at')->nullable();
        });
        Schema::create('login_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('fingerprint', 64);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 500);
            $t->boolean('suspicious')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn(['session_hash', 'revoked_at']));
        Schema::dropIfExists('tenant_invitations');
    }
};
