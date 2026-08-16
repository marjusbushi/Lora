<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales outlets (Restorant / Bar / Beach Bar) for the POS. Orders and
     * tables get a nullable outlet stamp; menu categories get a visibility
     * pivot where NO rows means "visible in every outlet" — so existing data
     * needs no backfill and a property with zero outlets keeps today's
     * single-POS behaviour untouched.
     *
     * SQLite (tests): plain native ADD COLUMN only — no table rebuild (the
     * #6996 lesson); FKs on ALTERed tables are MySQL-family only.
     */
    public function up(): void
    {
        Schema::create('pos_outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'pos_outlets_tenant_name_unique');
            $table->index(['tenant_id', 'is_active', 'sort_order'], 'pos_outlets_tenant_active_sort_index');
        });

        Schema::create('menu_category_pos_outlet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->cascadeOnDelete();

            $table->unique(['menu_category_id', 'pos_outlet_id'], 'menu_category_pos_outlet_unique');
            $table->index('pos_outlet_id', 'menu_category_pos_outlet_outlet_index');
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_id')->nullable()->after('pos_shift_id');
            $table->index(['tenant_id', 'outlet_id', 'status'], 'pos_orders_tenant_outlet_status_index');
        });

        Schema::table('pos_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'outlet_id'], 'pos_tables_tenant_outlet_index');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->foreign('outlet_id')->references('id')->on('pos_outlets')->nullOnDelete();
            });
            Schema::table('pos_tables', function (Blueprint $table) {
                $table->foreign('outlet_id')->references('id')->on('pos_outlets')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->dropForeign(['outlet_id']);
            });
            Schema::table('pos_tables', function (Blueprint $table) {
                $table->dropForeign(['outlet_id']);
            });
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropIndex('pos_orders_tenant_outlet_status_index');
            $table->dropColumn('outlet_id');
        });

        Schema::table('pos_tables', function (Blueprint $table) {
            $table->dropIndex('pos_tables_tenant_outlet_index');
            $table->dropColumn('outlet_id');
        });

        Schema::dropIfExists('menu_category_pos_outlet');
        Schema::dropIfExists('pos_outlets');
    }
};
