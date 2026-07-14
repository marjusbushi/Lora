<?php

namespace App\Models;

class MenuItem extends TenantModel
{
    protected $fillable = ['menu_category_id', 'name', 'price', 'cost_price', 'is_available', 'image_path'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function inventoryComponents()
    {
        return $this->hasMany(MenuItemInventory::class);
    }
}
