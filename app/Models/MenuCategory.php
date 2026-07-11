<?php

namespace App\Models;

class MenuCategory extends TenantModel
{
    protected $fillable = ['name', 'sort_order', 'outlet'];

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }
}
