<?php

namespace App\Services;

use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\Reservation;

/** Normalizes a mixed-currency folio into the reservation/invoice currency. */
class ReservationMoney
{
    public static function currency(Reservation $reservation): string
    {
        return strtoupper((string) ($reservation->currency ?: PricingCurrency::code()));
    }

    public static function exchangeRate(Reservation $reservation): float
    {
        return (float) ($reservation->exchange_rate ?: MoneySnapshot::make(1, self::currency($reservation))['exchange_rate']);
    }

    public static function folioAmount(Reservation $reservation, FolioItem $item): float
    {
        return self::recordAmount(
            $reservation,
            (float) $item->amount,
            (string) ($item->currency ?: BaseCurrency::code()),
            $item->exchange_rate !== null ? (float) $item->exchange_rate : null,
        );
    }

    public static function paymentAmount(Reservation $reservation, Payment $payment): float
    {
        return self::recordAmount(
            $reservation,
            (float) $payment->amount,
            (string) ($payment->currency ?: self::currency($reservation)),
            $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
        );
    }

    public static function recordAmount(Reservation $reservation, float $amount, string $currency, ?float $exchangeRate): float
    {
        return MoneySnapshot::convert(
            $amount,
            $currency,
            self::currency($reservation),
            $exchangeRate,
            self::exchangeRate($reservation),
        );
    }

    /** @return array{room:float,charges:float,discounts:float,paid:float,gross:float,outstanding:float} */
    public static function totals(Reservation $reservation): array
    {
        $reservation->loadMissing(['folioItems', 'payments']);
        $charges = $reservation->folioItems
            ->whereNotIn('type', ['discount', 'room'])
            ->sum(fn (FolioItem $item) => self::folioAmount($reservation, $item));
        $discounts = $reservation->folioItems
            ->where('type', 'discount')
            ->sum(fn (FolioItem $item) => self::folioAmount($reservation, $item));
        $paid = $reservation->payments
            ->reject(fn (Payment $payment) => $payment->is_voided)
            ->sum(fn (Payment $payment) => self::paymentAmount($reservation, $payment));
        $room = (float) $reservation->total_amount;
        $gross = round($room + $charges - $discounts, 2);

        return [
            'room' => round($room, 2),
            'charges' => round($charges, 2),
            'discounts' => round($discounts, 2),
            'paid' => round($paid, 2),
            'gross' => $gross,
            'outstanding' => round($gross - $paid, 2),
        ];
    }
}
