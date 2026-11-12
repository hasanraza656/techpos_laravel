<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->index(['tenant_id', 'warehouse_id', 'product_id', 'type', 'created_at'], 'movement_product_demand'));
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropIndex('movement_product_demand'));
    }
};
