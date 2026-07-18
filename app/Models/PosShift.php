<?php

namespace App\Models;

use App\Observers\PosShiftObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([PosShiftObserver::class])]
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

    public function payments()
    {
        return $this->hasMany(PosOrderPayment::class, 'pos_shift_id');
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
        $totals = $this->liveTotals();
        $cash = $totals['cash'];
        $card = $totals['card'];
        $room = $totals['room_charge'];

        $this->cash_sales = $cash;
        $this->card_sales = $card;
        $this->room_charge_sales = $room;
        $this->total_sales = round($cash + $card + $room, 2);
        $this->total_orders = $this->orders()->where('status', 'completed')->count();
        $this->cancelled_count = $this->orders()->where('status', 'cancelled')->count();

        $this->expected_cash = round((float) $this->opening_float + $cash, 2);
    }

    /** Net tender totals, including refunds and legacy orders created before tender rows existed. */
    public function liveTotals(): array
    {
        $rows = $this->payments()
            ->selectRaw("method, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as total")
            ->groupBy('method')
            ->pluck('total', 'method');

        $legacy = $this->orders()
            ->where('status', 'completed')
            ->whereDoesntHave('payments', fn ($query) => $query->where('direction', 'in'))
            ->selectRaw('payment_method, SUM(total_amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        return collect(['cash', 'card', 'room_charge'])->mapWithKeys(fn (string $method) => [
            $method => round((float) ($rows[$method] ?? 0) + (float) ($legacy[$method] ?? 0), 2),
        ])->all();
    }
}
