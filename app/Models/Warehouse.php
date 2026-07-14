<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends TenantModel
{
    protected $fillable = ['name', 'type', 'description', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function ensureDefault(): self
    {
        $default = static::query()->where('is_default', true)->where('is_active', true)->first();
        if ($default) {
            return $default;
        }

        $warehouse = static::query()->where('is_active', true)->orderBy('id')->first();
        if ($warehouse) {
            $warehouse->update(['is_default' => true]);

            return $warehouse;
        }

        return static::query()->create([
            'name' => 'Magazina qendrore', 'type' => 'central', 'is_default' => true, 'is_active' => true,
        ]);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
