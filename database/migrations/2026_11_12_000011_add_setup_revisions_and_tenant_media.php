<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_progress', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('deferred_at')->nullable();
        });
        Schema::create('tenant_media', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('slot', 100);
            $t->string('kind', 20);
            $t->string('path');
            $t->string('mime', 40);
            $t->unsignedInteger('bytes');
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->string('sha256', 64);
            $t->timestamps();
            $t->unique(['tenant_id', 'slot']);
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_media');
        Schema::table('onboarding_progress', function (Blueprint $t) {
            $t->dropColumn(['revision', 'deferred_at']);
        });
    }
};
