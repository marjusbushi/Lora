<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the free-text inventory_items.category with a managed tree:
     * root categories with up to TWO levels of subcategories below them
     * (Pije → Alkoolike → Verë), unlimited siblings, always optional.
     * Every distinct free-text value becomes a root category per tenant and
     * its items are linked before the old column is dropped — no data lost.
     *
     * SQLite caveat: inventory_items carries same-tenant integrity triggers
     * (2026_07_16_140000) and other tables' triggers reference it. Any table
     * REBUILD (which Laravel performs when adding an FK-constrained column)
     * collides with those triggers and would silently drop the table's own
     * ones. Every inventory_items change below therefore uses only native
     * ADD/DROP COLUMN, and the FK constraint is added on the MySQL family
     * alone — on SQLite (tests only) integrity is covered by application
     * validation and the mysql-migrations CI job.
     */
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            // restrictOnDelete: a parent with children cannot be deleted even
            // if application code forgets the emptiness check.
            $table->foreignId('parent_id')->nullable()->constrained('inventory_categories')->restrictOnDelete();
            $table->timestamps();
            // NULL parent_id escapes MySQL unique enforcement for roots —
            // root-name duplicates are additionally guarded in validation.
            $table->unique(['tenant_id', 'parent_id', 'name']);
            $table->index(['tenant_id', 'parent_id']);
        });

        // Plain nullable column: native ADD COLUMN on every driver, no rebuild.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('category');
            $table->index('category_id');
        });

        if ($this->isMySqlFamily()) {
            // restrictOnDelete mirrors the app rule: only empty categories die.
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->foreign('category_id')
                    ->references('id')->on('inventory_categories')
                    ->restrictOnDelete();
            });
        }

        // Backfill runs on raw tables — tenant scopes must not narrow it.
        $now = now();
        $values = DB::table('inventory_items')
            ->select('tenant_id', 'category')
            ->whereNotNull('category')
            ->whereRaw("TRIM(category) <> ''")
            ->distinct()
            ->get();

        foreach ($values as $value) {
            DB::table('inventory_categories')->updateOrInsert(
                ['tenant_id' => $value->tenant_id, 'parent_id' => null, 'name' => trim($value->category)],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach (DB::table('inventory_categories')->whereNull('parent_id')->get() as $category) {
            DB::table('inventory_items')
                ->where('tenant_id', $category->tenant_id)
                ->whereRaw('TRIM(category) = ?', [$category->name])
                ->update(['category_id' => $category->id]);
        }

        // Native DROP COLUMN — 'category' is referenced by no index, trigger or view.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('category', 80)->nullable()->after('barcode');
        });

        // Best-effort reverse: each item gets its linked category's own name
        // (a sub-subcategory flattens to its own label, ancestry is lost).
        // Correlated subquery instead of UPDATE..JOIN — SQLite has no join update.
        DB::table('inventory_items')
            ->whereNotNull('category_id')
            ->update(['category' => DB::raw(
                '(SELECT name FROM inventory_categories WHERE inventory_categories.id = inventory_items.category_id)'
            )]);

        if ($this->isMySqlFamily()) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
            });
        }
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
            $table->dropColumn('category_id');
        });
        Schema::dropIfExists('inventory_categories');
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
