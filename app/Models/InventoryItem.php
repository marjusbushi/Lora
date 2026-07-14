<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends TenantModel
{
    protected $fillable = [
        'name', 'sku', 'barcode', 'category', 'type', 'unit',
        'average_cost', 'selling_price', 'minimum_stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'average_cost' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'minimum_stock' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function billItems(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function stock(?int $warehouseId = null): float
    {
        return round((float) $this->movements()
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->sum('quantity'), 4);
    }
}
