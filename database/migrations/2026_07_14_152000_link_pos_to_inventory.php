<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('outlet')
                ->constrained('warehouses')->nullOnDelete();
        });

        Schema::create('menu_item_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->timestamps();
            $table->unique(['tenant_id', 'menu_item_id', 'inventory_item_id'], 'menu_item_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_inventory');
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
