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

        Schema::table('inventory_items', function (Blueprint $table) {
            // restrictOnDelete mirrors the app rule: only empty categories die.
            $table->foreignId('category_id')->nullable()->after('category')->constrained('inventory_categories')->restrictOnDelete();
        });

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
        DB::table('inventory_items')
            ->join('inventory_categories', 'inventory_items.category_id', '=', 'inventory_categories.id')
            ->update(['inventory_items.category' => DB::raw('inventory_categories.name')]);

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('inventory_categories');
    }
};
