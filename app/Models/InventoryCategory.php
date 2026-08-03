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

    public function menuCategories()
    {
        return $this->hasMany(MenuCategory::class);
    }

    /**
     * Flat, depth-annotated tree (roots first, children right below their
     * parent) — the shared shape for hierarchical selects and drill-downs.
     *
     * @return list<array{id:int,name:string,parent_id:?int,depth:int}>
     */
    public static function flatTree(): array
    {
        $byParent = static::query()->orderBy('name')->get(['id', 'name', 'parent_id'])
            ->groupBy(fn (self $category) => $category->parent_id ?? 0);

        $flat = [];
        $walk = function (int $parentKey, int $depth) use (&$walk, $byParent, &$flat) {
            foreach ($byParent->get($parentKey, collect()) as $category) {
                $flat[] = ['id' => $category->id, 'name' => $category->name, 'parent_id' => $category->parent_id, 'depth' => $depth];
                $walk($category->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $flat;
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

    /**
     * Every category id mapped to the NAME of its root ancestor — one query
     * for the whole tree, so spend rollups group leaves under their root
     * ("Energji Elektrike" reports as "Shpenzime Fikse").
     *
     * @return array<int, string>
     */
    public static function rootNameMap(): array
    {
        $nodes = static::query()->get(['id', 'parent_id', 'name'])->keyBy('id');

        $map = [];
        foreach ($nodes as $id => $node) {
            $root = $node;
            $guard = 0;
            while ($root->parent_id !== null && $guard < 3 && $nodes->has($root->parent_id)) {
                $root = $nodes->get($root->parent_id);
                $guard++;
            }
            $map[$id] = $root->name;
        }

        return $map;
    }
}
