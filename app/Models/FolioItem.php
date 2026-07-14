<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioItem extends TenantModel
{
    protected $fillable = [
        'reservation_id',
        'pos_order_id',
        'inventory_item_id',
        'warehouse_id',
        'inventory_quantity',
        'unit_price',
        'inventory_reference',
        'description',
        'amount',
        'type',
        'charge_date',
        'vat_rate',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'inventory_quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'charge_date' => 'date',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function posOrder()
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
