<?php

namespace App\Models;

class Payment extends TenantModel
{
    protected $fillable = [
        'reservation_id',
        'amount',
        'method',
        'created_by',
        'type',
        'is_voided',
        'pok_order_id',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_voided' => 'boolean',
        ];
    }

    /**
     * Exclude voided payments (refunds / chargebacks) from any query.
     * is_voided is nullable (default false) → a NULL row counts as NOT voided.
     */
    public function scopeNotVoided($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('is_voided')->orWhere('is_voided', false);
        });
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
