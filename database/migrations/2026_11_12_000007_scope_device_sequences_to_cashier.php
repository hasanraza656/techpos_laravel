<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->dropUnique('sale_device_sequence');
            $t->unique(['tenant_id', 'device_identifier', 'cashier_id', 'local_sequence'], 'sale_device_sequence');
        });
    }

    public function down(): void
    {
        if (DB::table('sales')->whereNotNull('device_identifier')->select('tenant_id', 'device_identifier', 'local_sequence')->groupBy('tenant_id', 'device_identifier', 'local_sequence')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore old sequence constraint: multiple cashiers have valid overlapping sequences. Preserve sales and roll forward.');
        }
        Schema::table('sales', function (Blueprint $t) {
            $t->dropUnique('sale_device_sequence');
            $t->unique(['tenant_id', 'device_identifier', 'local_sequence'], 'sale_device_sequence');
        });
    }
};
