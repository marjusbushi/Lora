<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Daily exchange rates for the 10 tracked currencies (EUR base), fetched from
 * ExchangeRate-API v6 ONCE per day (scheduler) or on demand from Settings.
 * Rates are read at WRITE time and frozen onto documents — a new day's rate
 * never rewrites yesterday's bills/payments.
 */
class CurrencyRates
{
    /** EUR is the base; these are the tracked quote currencies. */
    public const CURRENCIES = ['USD', 'GBP', 'ALL', 'CHF', 'TRY', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK'];

    public static function enabled(): bool
    {
        return (bool) Setting::get('currencies.enabled', false) && self::apiKey() !== '';
    }

    public static function apiKey(): string
    {
        return trim((string) Setting::get('currencies.api_key', ''));
    }

    /** @return array<string,float> code => units per 1 EUR */
    public static function rates(): array
    {
        $rates = Setting::get('currencies.rates', null);

        return is_array($rates) ? $rates : [];
    }

    public static function updatedAt(): ?string
    {
        return Setting::get('currencies.updated_at') ?: null;
    }

    /**
     * Units of $code per 1 EUR, or null when unknown. ALL falls back to the
     * manual financial.fx_all_per_eur so Finance keeps working with the API off.
     */
    public static function rate(string $code): ?float
    {
        $code = strtoupper($code);
        if ($code === 'EUR') {
            return 1.0;
        }

        $rate = self::rates()[$code] ?? null;
        if ($rate === null && $code === 'ALL') {
            $manual = (float) Setting::get('financial.fx_all_per_eur', 0);
            $rate = $manual > 0 ? $manual : null;
        }

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Units of quote currency per one unit of base currency. Rates are crossed
     * through EUR because the provider stores a single EUR-based rate table.
     */
    public static function between(string $baseCurrency, string $quoteCurrency): ?float
    {
        $baseCurrency = strtoupper($baseCurrency);
        $quoteCurrency = strtoupper($quoteCurrency);
        if ($baseCurrency === $quoteCurrency) {
            return 1.0;
        }

        $baseRate = self::rate($baseCurrency);
        $quoteRate = self::rate($quoteCurrency);
        if (! $baseRate || ! $quoteRate) {
            return null;
        }

        return round($quoteRate / $baseRate, 6);
    }

    /** Fetch today's rates and store them. Returns how many were stored. */
    public function fetch(): int
    {
        $resp = Http::timeout(20)->retry(2, 500, throw: false)
            ->get('https://v6.exchangerate-api.com/v6/'.self::apiKey().'/latest/EUR');

        if (! $resp->successful() || $resp->json('result') !== 'success') {
            throw new \RuntimeException('Currency API failed: '.($resp->json('error-type') ?? 'HTTP '.$resp->status()));
        }

        $all = (array) $resp->json('conversion_rates', []);
        $tracked = collect(self::CURRENCIES)
            ->mapWithKeys(fn ($c) => [$c => isset($all[$c]) ? round((float) $all[$c], 4) : null])
            ->filter()
            ->all();
        if ($tracked === []) {
            throw new \RuntimeException('Currency API returned no tracked rates.');
        }

        Setting::set('currencies.rates', $tracked, 'json');
        Setting::set('currencies.updated_at', now()->toDateTimeString(), 'text');

        return count($tracked);
    }
}
