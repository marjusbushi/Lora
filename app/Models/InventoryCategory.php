<?php

namespace App\Models;

/**
 * Managed inventory-article category tree: roots with up to TWO levels of
 * subcategories below them (Pije → Alkoolike → Verë), unlimited siblings.
 * The depth cap is enforced at write time (controller validation) and the
 * parent FK is restrictOnDelete, so a populated parent can never vanish.
 */
class InventoryCategory extends TenantModel
{
    protected $fillable = ['name', 'parent_id'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'category_id');
    }

    /** 0 = root, 1 = subcategory, 2 = sub-subcategory (the maximum). */
    public function depth(): int
    {
        $depth = 0;
        $node = $this;
        while ($node->parent_id !== null && $depth < 3) {
            $node->loadMissing('parent');
            $node = $node->parent;
            $depth++;
        }

        return $depth;
    }
}
