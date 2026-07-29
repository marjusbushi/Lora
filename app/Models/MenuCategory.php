<?php

namespace App\Models;

class MenuCategory extends TenantModel
{
    protected $fillable = ['name', 'inventory_category_id', 'sort_order', 'outlet', 'warehouse_id'];

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inventoryCategory()
    {
        return $this->belongsTo(InventoryCategory::class);
    }

    /**
     * The POS-side carrier for a tree node: finds the linked menu category or
     * creates it on first use (name mirrored from the node; outlet/warehouse
     * stay editable POS settings). Idempotent — one menu category per node.
     */
    public static function forInventoryCategory(InventoryCategory $node): self
    {
        $existing = static::query()->where('inventory_category_id', $node->id)->first();
        if ($existing) {
            return $existing;
        }

        return static::create([
            'name' => $node->name,
            'inventory_category_id' => $node->id,
            'sort_order' => (int) static::query()->max('sort_order') + 1,
            'outlet' => '',
        ]);
    }
}
