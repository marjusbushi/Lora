<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryItem extends TenantModel
{
    protected $fillable = [
        'name', 'sku', 'barcode', 'category', 'type', 'unit', 'image_path',
        'average_cost', 'selling_price', 'sell_in_pos', 'sell_in_rooms',
        'room_selling_price', 'room_warehouse_id', 'minimum_stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'average_cost' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'sell_in_pos' => 'boolean',
            'sell_in_rooms' => 'boolean',
            'room_selling_price' => 'decimal:2',
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

    public function roomWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'room_warehouse_id');
    }

    public function posMenuItem(): HasOne
    {
        return $this->hasOne(MenuItem::class);
    }

    public function stock(?int $warehouseId = null): float
    {
        return round((float) $this->movements()
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->sum('quantity'), 4);
    }
}
