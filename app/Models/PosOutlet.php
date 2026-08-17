<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class PosOutlet extends TenantModel
{
    protected $fillable = ['name', 'warehouse_id', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function menuCategories()
    {
        return $this->belongsToMany(MenuCategory::class, 'menu_category_pos_outlet');
    }

    public function orders()
    {
        return $this->hasMany(PosOrder::class, 'outlet_id');
    }

    public function tables()
    {
        return $this->hasMany(PosTable::class, 'outlet_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
