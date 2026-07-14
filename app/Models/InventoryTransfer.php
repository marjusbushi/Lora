<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InventoryTransfer extends TenantModel
{
    protected $fillable = [
        'inventory_item_id', 'from_warehouse_id', 'to_warehouse_id',
        'quantity', 'notes', 'transferred_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'transferred_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'sourceable');
    }
}
