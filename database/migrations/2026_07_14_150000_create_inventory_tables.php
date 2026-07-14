<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 30)->default('other');
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('sku', 60);
            $table->string('barcode', 80)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('type', 30)->default('product');
            $table->string('unit', 20)->default('piece');
            $table->decimal('average_cost', 14, 4)->default(0);
            $table->decimal('selling_price', 12, 2)->nullable();
            $table->decimal('minimum_stock', 14, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'is_active', 'type']);
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->string('notes', 300)->nullable();
            $table->timestamp('transferred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'transferred_at']);
        });

        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 14, 4);
            $table->string('unit', 20);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('line_total', 14, 2);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'bill_id']);
            $table->index(['tenant_id', 'inventory_item_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('type', 30);
            // Signed quantity: positive = stock in, negative = stock out.
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->nullableMorphs('sourceable');
            $table->string('notes', 300)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'inventory_item_id', 'warehouse_id'], 'inventory_movements_stock_index');
            $table->index(['tenant_id', 'occurred_at']);
            $table->unique(
                ['tenant_id', 'sourceable_type', 'sourceable_id', 'type', 'warehouse_id', 'inventory_item_id'],
                'inventory_movements_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('warehouses');
    }
};
