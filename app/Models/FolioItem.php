<?php

namespace App\Models;

class FolioItem extends TenantModel
{
    protected $fillable = [
        'reservation_id',
        'pos_order_id',
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
}
