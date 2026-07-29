<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Category unification, step 1 (the bridge): every POS menu category maps
     * onto a node of the inventory category tree. menu_categories stays as
     * the POS-side carrier of outlet/source-warehouse/sort — but its NAME and
     * EXISTENCE now follow the tree. Existing menu categories are matched to
     * root tree nodes by name (created when missing) so no menu item loses
     * its group.
     *
     * SQLite (tests): plain native ADD COLUMN only — no table rebuild (the
     * #6996 lesson); the FK is MySQL-family only.
     */
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_category_id')->nullable()->after('name');
            $table->index('inventory_category_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // restrictOnDelete backstops the app rule: a tree node with a
            // linked POS group cannot vanish underneath the menu.
            Schema::table('menu_categories', function (Blueprint $table) {
                $table->foreign('inventory_category_id')
                    ->references('id')->on('inventory_categories')
                    ->restrictOnDelete();
            });
        }

        // Backfill on raw tables — tenant scopes must not narrow it.
        $now = now();
        foreach (DB::table('menu_categories')->get() as $menuCategory) {
            $name = trim((string) $menuCategory->name);
            if ($name === '') {
                continue;
            }

            DB::table('inventory_categories')->updateOrInsert(
                ['tenant_id' => $menuCategory->tenant_id, 'parent_id' => null, 'name' => $name],
                ['created_at' => $now, 'updated_at' => $now],
            );
            $node = DB::table('inventory_categories')
                ->where('tenant_id', $menuCategory->tenant_id)
                ->whereNull('parent_id')
                ->where('name', $name)
                ->first();

            DB::table('menu_categories')
                ->where('id', $menuCategory->id)
                ->update(['inventory_category_id' => $node->id]);
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('menu_categories', function (Blueprint $table) {
                $table->dropForeign(['inventory_category_id']);
            });
        }
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->dropIndex(['inventory_category_id']);
            $table->dropColumn('inventory_category_id');
        });
    }
};
