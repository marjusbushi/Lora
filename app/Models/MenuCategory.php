<?php

namespace App\Models;

class MenuCategory extends TenantModel
{
    protected $fillable = ['name', 'sort_order', 'outlet', 'warehouse_id'];

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
