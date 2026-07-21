<?php

namespace App\Models;

use App\Services\BaseCurrency;
use App\Services\MoneySnapshot;
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
        'currency',
        'exchange_rate',
        'amount_base',
        'type',
        'charge_date',
        'vat_rate',
    ];

    protected static function booted(): void
    {
        static::saving(function (FolioItem $item) {
            $item->currency = strtoupper((string) ($item->currency ?: BaseCurrency::code()));
            if (! $item->exchange_rate) {
                $item->exchange_rate = MoneySnapshot::make(1, $item->currency)['exchange_rate'];
            }
            if ($item->isDirty('amount') || $item->amount_base === null) {
                $item->amount_base = round((float) $item->amount * (float) $item->exchange_rate, 2);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'amount_base' => 'decimal:2',
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
