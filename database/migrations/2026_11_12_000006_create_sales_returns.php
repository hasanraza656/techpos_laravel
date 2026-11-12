<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('sale_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('shift_id');
            $t->foreignId('user_id')->constrained();
            $t->string('payload_hash', 64);
            $t->string('receipt_number', 60);
            $t->decimal('total', 14, 2);
            $t->decimal('tax_total', 14, 2);
            $t->decimal('cost_reversed', 20, 4);
            $t->string('reason', 255);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'branch_id', 'created_at']);
            $t->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales');
            $t->foreign(['tenant_id', 'branch_id', 'warehouse_id'])->references(['tenant_id', 'branch_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'branch_id', 'shift_id'])->references(['tenant_id', 'branch_id', 'id'])->on('register_shifts');
        });
        Schema::create('sale_return_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('return_id');
            $t->unsignedBigInteger('sale_line_id');
            $t->decimal('quantity', 16, 3);
            $t->decimal('total', 14, 2);
            $t->decimal('tax', 14, 2);
            $t->decimal('cost_allocated', 20, 4);
            $t->decimal('cost_reversed', 20, 4);
            $t->boolean('restocked');
            $t->unique(['return_id', 'sale_line_id']);
            $t->foreign(['tenant_id', 'return_id'])->references(['tenant_id', 'id'])->on('sale_returns');
            $t->foreign(['tenant_id', 'sale_line_id'])->references(['tenant_id', 'id'])->on('sale_lines');
        });
        foreach (['sale_returns', 'sale_return_lines'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_immutable_'.strtolower($operation);
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON $table BEGIN SELECT RAISE(ABORT, 'Finalized history is immutable'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finalized history is immutable'");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
