<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_items', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->after('pos_order_id')
                ->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('inventory_item_id')
                ->constrained('warehouses')->nullOnDelete();
            $table->decimal('inventory_quantity', 14, 4)->nullable()->after('warehouse_id');
            $table->decimal('unit_price', 12, 2)->nullable()->after('inventory_quantity');
            $table->uuid('inventory_reference')->nullable()->after('unit_price');
            $table->unique(['tenant_id', 'inventory_reference'], 'folio_inventory_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('folio_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropUnique('folio_inventory_reference_unique');
            $table->dropColumn(['inventory_quantity', 'unit_price', 'inventory_reference']);
        });
    }
};
