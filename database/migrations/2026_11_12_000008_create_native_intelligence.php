<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained();
            $t->json('parameters');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('reorder_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('product_id');
            $t->foreignId('user_id')->constrained();
            $t->string('proposal_key', 64);
            $t->string('decision', 20);
            $t->string('note', 500);
            $t->json('evidence');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'proposal_key']);
            $t->foreign(['tenant_id', 'branch_id', 'warehouse_id'])->references(['tenant_id', 'branch_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('assistant_access_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->string('tool', 60);
            $t->json('arguments');
            $t->unsignedInteger('result_count');
            $t->unsignedInteger('duration_ms');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->index(['tenant_id', 'warehouse_id', 'created_at', 'product_id'], 'movement_intelligence_window'));
        $feature = DB::table('features')->insertGetId(['key' => 'builtin_intelligence', 'name' => 'Built-in Intelligence', 'category' => 'Reports', 'description' => 'Authorized low-stock reports and deterministic report selection; advanced forecasts still need their own plan entitlement.', 'dependencies' => json_encode(['daily_summary']), 'requires_configuration' => false]);
        foreach (DB::table('plans')->pluck('id') as $plan) {
            DB::table('feature_entitlements')->insert(['plan_id' => $plan, 'feature_id' => $feature]);
        }
    }

    public function down(): void
    {
        DB::table('features')->where('key', 'builtin_intelligence')->delete();
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropIndex('movement_intelligence_window'));
        Schema::dropIfExists('assistant_access_logs');
        Schema::dropIfExists('reorder_reviews');
        Schema::dropIfExists('intelligence_settings');
    }
};
