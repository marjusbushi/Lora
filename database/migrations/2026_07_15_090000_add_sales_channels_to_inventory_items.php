<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('unit');
            $table->boolean('sell_in_pos')->default(false)->after('selling_price');
            $table->boolean('sell_in_rooms')->default(false)->after('sell_in_pos');
            $table->decimal('room_selling_price', 12, 2)->nullable()->after('sell_in_rooms');
            $table->foreignId('room_warehouse_id')->nullable()->after('room_selling_price')
                ->constrained('warehouses')->nullOnDelete();
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->after('menu_category_id')
                ->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('inventory_item_id')
                ->constrained('warehouses')->nullOnDelete();
            $table->unique(['tenant_id', 'inventory_item_id'], 'menu_items_tenant_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropUnique('menu_items_tenant_inventory_unique');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('inventory_item_id');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_warehouse_id');
            $table->dropColumn(['image_path', 'sell_in_pos', 'sell_in_rooms', 'room_selling_price']);
        });
    }
};
