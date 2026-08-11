<?php

namespace App\Models;

use App\Observers\PaymentObserver;
use App\Services\MoneySnapshot;
use App\Services\PricingCurrency;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([PaymentObserver::class])]
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
        'exchange_rate',
        'amount_base',
    ];

    protected static function booted(): void
    {
        static::saving(function (Payment $payment) {
            if (! $payment->currency) {
                $payment->currency = $payment->reservation?->currency ?: PricingCurrency::code();
            }
            $payment->currency = strtoupper((string) $payment->currency);
            if (! $payment->exchange_rate) {
                // A payment against a reservation in the SAME currency must
                // freeze the reservation's rate, not today's — otherwise the
                // folio "paid" and "owed" sides convert at different rates and
                // fully-paid stays show phantom-cent differences.
                $reservationRate = strtoupper((string) $payment->reservation?->currency) === $payment->currency
                    ? (float) $payment->reservation?->exchange_rate
                    : 0.0;
                $payment->exchange_rate = $reservationRate > 0
                    ? $reservationRate
                    : MoneySnapshot::make(1, $payment->currency)['exchange_rate'];
            }
            if ($payment->isDirty('amount') || $payment->amount_base === null) {
                $payment->amount_base = round((float) $payment->amount * (float) $payment->exchange_rate, 2);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'amount_base' => 'decimal:2',
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
