<?php

namespace App\Models;

class PosOrder extends TenantModel
{
    protected $fillable = [
        'reservation_id',
        'table_number',
        'status',
        'payment_method',
        'total_amount',
        'created_by',
        'pos_shift_id',
        'paid_at',
        'business_date',
        'covers',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'business_date' => 'date',
            'covers' => 'integer',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(PosOrderItem::class);
    }

    public function shift()
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function recalculateTotal(): void
    {
        $this->update([
            'total_amount' => $this->items()->sum('total_price'),
        ]);
    }
}
