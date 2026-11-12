<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->unique(['tenant_id', 'branch_id', 'id'], 'warehouse_tenant_branch_id'));
        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('public_id')->unique();
            $t->string('name', 120);
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'name']);
            $t->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('product_categories');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('public_id')->unique();
            $t->string('sku', 80);
            $t->string('name', 180);
            $t->string('alternate_name', 180)->nullable();
            $t->string('brand', 100)->nullable();
            $t->unsignedBigInteger('category_id')->nullable();
            $t->text('description')->nullable();
            $t->string('type', 30)->default('standard');
            $t->string('unit', 30)->default('piece');
            $t->decimal('cost_price', 18, 4)->default(0);
            $t->decimal('selling_price', 14, 2);
            $t->decimal('wholesale_price', 14, 2)->nullable();
            $t->decimal('tax_rate', 7, 4)->default(0);
            $t->boolean('tax_inclusive')->default(false);
            $t->decimal('reorder_level', 16, 3)->default(0);
            $t->boolean('track_stock')->default(true);
            $t->boolean('active')->default(true);
            $t->json('attributes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'sku']);
            $t->index(['tenant_id', 'active', 'name']);
            $t->index(['tenant_id', 'updated_at', 'id']);
            $t->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('product_categories');
        });
        Schema::create('product_barcodes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('product_id');
            $t->string('barcode', 100);
            $t->decimal('quantity', 16, 3)->default(1);
            $t->unique(['tenant_id', 'barcode']);
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('product_price_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('product_id');
            $t->foreignId('user_id')->constrained();
            $t->decimal('old_price', 14, 2)->nullable();
            $t->decimal('new_price', 14, 2);
            $t->timestamp('created_at')->useCurrent();
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('public_id')->unique();
            $t->string('name', 150);
            $t->string('phone', 40)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('address')->nullable();
            $t->string('group_name', 80)->nullable();
            $t->string('price_tier', 20)->default('retail');
            $t->decimal('credit_limit', 14, 2)->default(0);
            $t->boolean('marketing_consent')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'phone']);
            $t->index(['tenant_id', 'name']);
        });
        Schema::create('registers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->uuid('public_id')->unique();
            $t->string('name', 100);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'branch_id', 'id']);
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::create('register_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('register_id');
            $t->uuid('public_id')->unique();
            $t->foreignId('user_id')->constrained();
            $t->decimal('opening_float', 14, 2);
            $t->timestamp('opened_at')->useCurrent();
            $t->timestamp('closed_at')->nullable();
            $t->decimal('expected_cash', 14, 2)->nullable();
            $t->decimal('actual_cash', 14, 2)->nullable();
            $t->decimal('cash_difference', 14, 2)->nullable();
            $t->text('closing_note')->nullable();
            $t->uuid('open_key')->unique();
            $t->uuid('close_key')->nullable()->unique();
            $t->unique(['tenant_id', 'branch_id', 'id']);
            $t->foreign(['tenant_id', 'branch_id', 'register_id'])->references(['tenant_id', 'branch_id', 'id'])->on('registers');
            $t->index(['tenant_id', 'register_id', 'closed_at']);
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity', 16, 3)->default(0);
            $t->decimal('reserved_quantity', 16, 3)->default(0);
            $t->decimal('cost_value', 20, 4)->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'warehouse_id', 'product_id']);
            $t->foreign(['tenant_id', 'branch_id', 'warehouse_id'])->references(['tenant_id', 'branch_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('shift_id');
            $t->foreignId('cashier_id')->constrained('users');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->uuid('public_id')->unique();
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->string('receipt_number', 60);
            $t->string('currency', 3);
            $t->string('channel', 20)->default('online');
            $t->uuid('device_identifier')->nullable();
            $t->unsignedBigInteger('local_sequence')->nullable();
            $t->unsignedSmallInteger('payload_version')->default(1);
            $t->dateTime('client_occurred_at')->nullable();
            $t->timestamp('finalized_at')->useCurrent();
            foreach (['subtotal', 'line_discount', 'cart_discount', 'tax_total', 'total', 'amount_tendered', 'change_due'] as $column) {
                $t->decimal($column, 14, 2);
            }$t->decimal('cost_total', 20, 4);
            $t->text('note')->nullable();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->unique(['tenant_id', 'receipt_number']);
            $t->unique(['tenant_id', 'device_identifier', 'local_sequence'], 'sale_device_sequence');
            $t->index(['tenant_id', 'branch_id', 'finalized_at']);
            $t->foreign(['tenant_id', 'branch_id', 'warehouse_id'])->references(['tenant_id', 'branch_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'branch_id', 'shift_id'])->references(['tenant_id', 'branch_id', 'id'])->on('register_shifts');
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
        });
        Schema::create('sale_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('sale_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedInteger('position');
            $t->string('name', 180);
            $t->string('sku', 80);
            $t->string('unit', 30);
            $t->decimal('quantity', 16, 3);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('tax_rate', 7, 4);
            $t->boolean('tax_inclusive');
            $t->boolean('stock_tracked');
            foreach (['subtotal', 'line_discount', 'cart_discount', 'tax', 'total'] as $column) {
                $t->decimal($column, 14, 2);
            }$t->decimal('cost_value', 20, 4);
            $t->unique(['tenant_id', 'id']);
            $t->unique(['sale_id', 'position']);
            $t->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('sale_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('sale_id');
            $t->string('method', 30);
            $t->decimal('amount', 14, 2);
            $t->string('reference', 120)->nullable();
            $t->string('verification', 40);
            $t->timestamp('created_at')->useCurrent();
            $t->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales');
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('product_id');
            $t->foreignId('user_id')->constrained();
            $t->string('type', 40);
            $t->decimal('quantity', 16, 3);
            $t->decimal('reserved_delta', 16, 3)->default(0);
            $t->decimal('cost_value', 20, 4);
            $t->string('source_type', 40);
            $t->uuid('source_id');
            $t->string('reason', 255);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'source_type', 'source_id', 'product_id', 'type'], 'movement_source_unique');
            $t->index(['tenant_id', 'warehouse_id', 'product_id', 'id'], 'movement_lookup');
            $t->foreign(['tenant_id', 'branch_id', 'warehouse_id'])->references(['tenant_id', 'branch_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('cash_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('shift_id');
            $t->foreignId('user_id')->constrained();
            $t->uuid('idempotency_key');
            $t->string('type', 30);
            $t->decimal('amount', 14, 2);
            $t->string('reason', 255);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->foreign(['tenant_id', 'branch_id', 'shift_id'])->references(['tenant_id', 'branch_id', 'id'])->on('register_shifts');
        });
        Schema::create('held_sales', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('branch_id');
            $t->foreignId('user_id')->constrained();
            $t->string('name', 100);
            $t->json('payload');
            $t->timestamps();
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $t->index(['tenant_id', 'branch_id', 'user_id']);
        });
        foreach (['inventory.view', 'inventory.adjust', 'register.manage', 'customers.view', 'customers.manage'] as $key) {
            [$module,$action] = explode('.', $key);
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'module' => $module, 'name' => ucfirst($action).' '.$module, 'description' => 'Permission for '.$key.' within the assigned branch.']);
        }
        // Existing branches gain real default resources. No products, stock or transactions are invented.
        DB::table('branches')->whereNull('deleted_at')->orderBy('id')->chunkById(100, function ($branches) {
            foreach ($branches as $b) {
                if (! DB::table('warehouses')->where('tenant_id', $b->tenant_id)->where('branch_id', $b->id)->exists()) {
                    DB::table('warehouses')->insert(['tenant_id' => $b->tenant_id, 'branch_id' => $b->id, 'public_id' => (string) Str::uuid(), 'name' => 'Main stockroom', 'created_at' => now(), 'updated_at' => now()]);
                }DB::table('registers')->insert(['tenant_id' => $b->tenant_id, 'branch_id' => $b->id, 'public_id' => (string) Str::uuid(), 'name' => 'Register 1', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        foreach (['sales', 'sale_lines', 'sale_payments', 'inventory_movements', 'cash_events', 'product_price_history'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                $trigger = 'immutable_'.$table.'_'.strtolower($action);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER $trigger BEFORE $action ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Append-only operational history cannot be changed'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER $trigger BEFORE $action ON $table BEGIN SELECT RAISE(ABORT, 'Append-only operational history cannot be changed'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['held_sales', 'cash_events', 'inventory_movements', 'sale_payments', 'sale_lines', 'sales', 'stock_balances', 'register_shifts', 'registers', 'customers', 'product_price_history', 'product_barcodes', 'products', 'product_categories'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('warehouses',fn (Blueprint $t) => $t->dropUnique('warehouse_tenant_branch_id'));
    }
};
