<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revoked_sessions', function (Blueprint $t) {
            $t->string('session_hash', 64)->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->dateTime('expires_at')->index();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_sessions');
    }
};
