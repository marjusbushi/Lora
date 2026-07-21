<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Creates immutable source/base currency snapshots for operational records.
 * exchange_rate always means: base-currency units per 1 source-currency unit.
 */
class MoneySnapshot
{
    /** @return array{currency:string,exchange_rate:float,amount_base:float} */
    public static function make(float $amount, string $currency, ?float $exchangeRate = null): array
    {
        $currency = strtoupper($currency);
        $base = BaseCurrency::code();
        $exchangeRate ??= CurrencyRates::between($currency, $base);

        if ($exchangeRate === null || $exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'currency' => "Kursi {$currency}/{$base} mungon. Përditëso kurset te Cilësimet → Monedhat.",
            ]);
        }

        return [
            'currency' => $currency,
            'exchange_rate' => round($exchangeRate, 6),
            'amount_base' => round($amount * $exchangeRate, 2),
        ];
    }

    public static function convert(float $amount, string $from, string $to, ?float $fromToBase = null, ?float $toToBase = null): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return round($amount, 2);
        }

        $base = BaseCurrency::code();
        $fromToBase ??= CurrencyRates::between($from, $base);
        $toToBase ??= CurrencyRates::between($to, $base);
        if (! $fromToBase || ! $toToBase) {
            throw ValidationException::withMessages([
                'currency' => "Kursi {$from}/{$to} mungon. Përditëso kurset te Cilësimet → Monedhat.",
            ]);
        }

        return round(($amount * $fromToBase) / $toToBase, 2);
    }
}
