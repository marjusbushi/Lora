<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beach V2 (QR ordering): a sunbed order IS a normal PosOrder — it only
     * gains the unit it came from and a public token for the guest's status
     * page. Both nullable, so every existing POS flow is untouched.
     *
     * SQLite (tests): plain ADD COLUMN only; the FK is MySQL-family only
     * (the #6996 lesson). NO composite same-tenant FK here on purpose — the
     * beach setup can regenerate/delete units, and that flow relies on
     * nullOnDelete surviving (same reasoning as pos_outlets.warehouse_id);
     * app-layer TenantRule + the global tenant scope guard the writes.
     */
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('beach_unit_id')->nullable()->after('outlet_id');
            $table->char('guest_token', 40)->nullable()->unique();
            $table->index(['tenant_id', 'beach_unit_id'], 'pos_orders_tenant_beach_unit_index');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->foreign('beach_unit_id')->references('id')->on('beach_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->dropForeign(['beach_unit_id']);
            });
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropIndex('pos_orders_tenant_beach_unit_index');
            $table->dropUnique(['guest_token']);
            $table->dropColumn(['beach_unit_id', 'guest_token']);
        });
    }
};
