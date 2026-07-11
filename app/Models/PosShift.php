<?php

namespace App\Models;

class PosShift extends TenantModel
{
    protected $fillable = [
        'user_id',
        'status',
        'opening_float',
        'opened_at',
        'closed_at',
        'closed_by',
        'expected_cash',
        'counted_cash',
        'over_short',
        'cash_sales',
        'card_sales',
        'room_charge_sales',
        'total_sales',
        'total_orders',
        'cancelled_count',
        'closing_note',
    ];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'over_short' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'card_sales' => 'decimal:2',
            'room_charge_sales' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function orders()
    {
        return $this->hasMany(PosOrder::class, 'pos_shift_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * The single open shift owned by a user (per-user model: at most one open at a time).
     */
    public static function currentFor(int $userId): ?self
    {
        return static::where('user_id', $userId)->where('status', 'open')->first();
    }

    /**
     * Freeze the closing snapshot from this shift's COMPLETED orders. Only cash hits
     * the drawer — card and room_charge are reported but excluded from expected_cash
     * (room charges are paid by the guest at checkout via the folio, not the till).
     * Sets attributes only; the caller persists after recording counted_cash/over_short.
     */
    public function computeTotals(): void
    {
        $cash = (float) $this->orders()->where('status', 'completed')->where('payment_method', 'cash')->sum('total_amount');
        $card = (float) $this->orders()->where('status', 'completed')->where('payment_method', 'card')->sum('total_amount');
        $room = (float) $this->orders()->where('status', 'completed')->where('payment_method', 'room_charge')->sum('total_amount');

        $this->cash_sales = $cash;
        $this->card_sales = $card;
        $this->room_charge_sales = $room;
        $this->total_sales = round($cash + $card + $room, 2);
        $this->total_orders = $this->orders()->where('status', 'completed')->count();
        $this->cancelled_count = $this->orders()->where('status', 'cancelled')->count();

        $this->expected_cash = round((float) $this->opening_float + $cash, 2);
    }
}
