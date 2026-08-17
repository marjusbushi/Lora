<?php

namespace App\Models;

use App\Observers\BeachShiftObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([BeachShiftObserver::class])]
class BeachShift extends TenantModel
{
    protected $fillable = [
        'user_id', 'status', 'opening_float', 'opened_at', 'closed_at', 'closed_by',
        'expected_cash', 'counted_cash', 'over_short',
        'cash_sales', 'card_sales', 'total_sales', 'total_paid', 'closing_note',
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
            'total_sales' => 'decimal:2',
            'total_paid' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Maksimumi një turn i hapur për përdorues. */
    public static function currentFor(int $userId): ?self
    {
        return static::where('user_id', $userId)->where('status', 'open')->first();
    }

    /**
     * Ngrin snapshot-in e mbylljes nga pagesat e shënuara gjatë turnit. Vetëm
     * cash-i hyn në sirtar; kartat raportohen por s'priten në expected_cash
     * (pagesat online s'lidhen kurrë me turn). Vetëm vendos atributet — thirrësi
     * i ruan pasi shënon counted_cash/over_short.
     */
    public function computeTotals(): void
    {
        $cash = (float) $this->reservations()->where('payment_method', 'cash')->whereNotNull('paid_at')->sum('total_amount');
        $card = (float) $this->reservations()->where('payment_method', 'card')->whereNotNull('paid_at')->sum('total_amount');

        $this->cash_sales = round($cash, 2);
        $this->card_sales = round($card, 2);
        $this->total_sales = round($cash + $card, 2);
        $this->total_paid = $this->reservations()->whereNotNull('paid_at')->count();
        $this->expected_cash = $this->liveExpectedCash($cash);
    }

    /** Çfarë duhet të ketë sirtari tani. */
    public function liveExpectedCash(?float $cash = null): float
    {
        $cash ??= (float) $this->reservations()->where('payment_method', 'cash')->whereNotNull('paid_at')->sum('total_amount');

        return round((float) $this->opening_float + $cash, 2);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BeachReservation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
