<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class MenuCategory extends TenantModel
{
    protected $fillable = ['name', 'inventory_category_id', 'sort_order', 'outlet', 'warehouse_id'];

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function outlets()
    {
        return $this->belongsToMany(PosOutlet::class, 'menu_category_pos_outlet');
    }

    /**
     * Visibility contract: a category with NO pivot rows is visible in every
     * outlet; with rows, only in the linked ones. Null outlet = no filtering.
     */
    public function scopeVisibleForOutlet(Builder $query, ?int $outletId): Builder
    {
        if ($outletId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($outletId) {
            $q->whereDoesntHave('outlets')
                ->orWhereHas('outlets', fn (Builder $o) => $o->whereKey($outletId));
        });
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
