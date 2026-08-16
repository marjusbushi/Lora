<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class BeachZone extends TenantModel
{
    protected $fillable = ['name', 'price_per_day', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'price_per_day' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(BeachUnit::class);
    }
}
