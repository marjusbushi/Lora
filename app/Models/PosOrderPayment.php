<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosOrderPayment extends TenantModel
{
    protected $fillable = [
        'pos_order_id', 'pos_shift_id', 'direction', 'method', 'amount',
        'refunded_from_id', 'reference', 'paid_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function refundedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refunded_from_id');
    }
}
