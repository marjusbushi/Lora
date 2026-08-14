<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same-tenant composite FKs for the outlet columns, extending the
     * 2026_07_14_161000 hardening to the new pos_outlets surface: even a raw
     * write (importer, tinker repair) cannot stamp tenant A's order/table with
     * tenant B's outlet, or tenant B's warehouse onto tenant A's outlet.
     * Nullable child columns keep MATCH SIMPLE semantics — NULL outlet rows
     * (legacy single-POS) are untouched. MySQL-family only, like the sibling
     * FK statements in the create migration; SQLite tests rely on the
     * app-layer TenantRule + global-scope guards.
     *
     * Deliberate deferral (board-reviewed): menu_category_pos_outlet stays
     * without tenant_id — both endpoints are tenant-scoped, the sync is
     * TenantRule-validated, and a rogue pivot row is contained (it can only
     * widen visibility inside its own tenant, never leak across).
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'outlet_id'])
                ->references(['tenant_id', 'id'])->on('pos_outlets');
        });

        // Both composites stay RESTRICT (a composite SET NULL would try to
        // null tenant_id too): pos_orders can never hit it (an outlet with
        // orders is refused deletion and deactivated instead), and
        // destroyPosOutlet() detaches tables explicitly before deleting.
        // NO composite on pos_outlets.warehouse_id: warehouse deletion is a
        // legacy inventory flow outside this feature — its single-column
        // nullOnDelete must keep working; app-layer TenantRule guards it.
        Schema::table('pos_tables', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'outlet_id'])
                ->references(['tenant_id', 'id'])->on('pos_outlets');
        });
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('pos_tables', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'outlet_id']);
        });
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'outlet_id']);
        });

        // MySQL keeps the implicit supporting indexes after the FK drop —
        // remove exactly what this migration introduced.
        foreach ([
            ['pos_tables', 'pos_tables_tenant_id_outlet_id_foreign'],
            ['pos_orders', 'pos_orders_tenant_id_outlet_id_foreign'],
        ] as [$tableName, $indexName]) {
            $exists = collect(Schema::getIndexes($tableName))
                ->contains(fn (array $index) => $index['name'] === $indexName);
            if ($exists) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        // pos_outlets keeps tenant-first index coverage via
        // pos_outlets_tenant_active_sort_index, so the plain tenant FK
        // survives the unique drop.
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
