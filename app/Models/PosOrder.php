<?php

namespace App\Models;

class PosOrder extends TenantModel
{
    protected $fillable = [
        'reservation_id',
        'table_number',
        'status',
        'payment_method',
        'subtotal_amount',
        'discount_amount',
        'discount_reason',
        'is_complimentary',
        'total_amount',
        'created_by',
        'pos_shift_id',
        'paid_at',
        'business_date',
        'covers',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'refunded_at',
        'refunded_by',
        'refund_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'is_complimentary' => 'boolean',
            'paid_at' => 'datetime',
            'business_date' => 'date',
            'covers' => 'integer',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
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

    public function fiscalDocument()
    {
        return $this->hasOne(PosFiscalDocument::class, 'pos_order_id');
    }

    public function payments()
    {
        return $this->hasMany(PosOrderPayment::class, 'pos_order_id');
    }

    public function salePayments()
    {
        return $this->hasMany(PosOrderPayment::class, 'pos_order_id')->where('direction', 'in');
    }

    public function refundPayments()
    {
        return $this->hasMany(PosOrderPayment::class, 'pos_order_id')->where('direction', 'out');
    }

    public function recalculateTotal(): void
    {
        $subtotal = (float) $this->items()->sum('total_price');
        $discount = min($subtotal, max(0, (float) $this->discount_amount));
        $this->update([
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => round($subtotal - $discount, 2),
        ]);
    }
}
