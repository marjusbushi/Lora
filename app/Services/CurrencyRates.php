<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Daily exchange rates for the 10 tracked currencies (EUR base), fetched from
 * ExchangeRate-API v6 ONCE per day FOR THE WHOLE PLATFORM (scheduler) or on
 * demand from the super-admin control panel. Every hotel reads the same
 * platform table; only the manual ALL/EUR fallback stays per hotel.
 * Rates are read at WRITE time and frozen onto documents — a new day's rate
 * never rewrites yesterday's bills/payments.
 */
class CurrencyRates
{
    /** EUR is the base; these are the tracked quote currencies. */
    public const CURRENCIES = ['USD', 'GBP', 'ALL', 'CHF', 'TRY', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK'];

    /** Per-hotel rate mode: platform-synced, or the hotel's own manual ALL rate. */
    public const MODE_AUTOMATIC = 'automatic';

    public const MODE_MANUAL = 'manual';

    /**
     * The hotel's chosen mode. Automatic (default): platform rates, manual
     * ALL only as emergency fallback. Manual: the hotel's ALL/EUR rate wins;
     * other currencies still come from the platform.
     */
    public static function mode(): string
    {
        return Setting::get('currencies.mode') === self::MODE_MANUAL
            ? self::MODE_MANUAL
            : self::MODE_AUTOMATIC;
    }

    public static function enabled(): bool
    {
        return (bool) PlatformSetting::get('currencies.enabled', false) && self::apiKey() !== '';
    }

    public static function apiKey(): string
    {
        return trim((string) PlatformSetting::get('currencies.api_key', ''));
    }

    /** @return array<string,float> code => units per 1 EUR */
    public static function rates(): array
    {
        $rates = PlatformSetting::get('currencies.rates', null);

        if (is_array($rates) && $rates !== []) {
            return $rates;
        }

        // Transition: hotels configured before the platform-level integration
        // may still carry their own fetched table (tenant settings). Honor it
        // until the first platform fetch lands, then the platform table wins.
        $legacy = Setting::get('currencies.rates', null);

        return is_array($legacy) ? $legacy : [];
    }

    public static function updatedAt(): ?string
    {
        $platformRates = PlatformSetting::get('currencies.rates', null);

        if (is_array($platformRates) && $platformRates !== []) {
            return PlatformSetting::get('currencies.updated_at') ?: null;
        }

        return Setting::get('currencies.updated_at') ?: null;
    }

    /**
     * Units of $code per 1 EUR, or null when unknown. The per-hotel manual
     * financial.fx_all_per_eur governs ALL: in manual mode it wins outright;
     * in automatic mode it is only the fallback when no fetched rate exists,
     * so Finance keeps working with the API off.
     */
    public static function rate(string $code): ?float
    {
        $code = strtoupper($code);
        if ($code === 'EUR') {
            return 1.0;
        }

        $manual = (float) Setting::get('financial.fx_all_per_eur', 0);

        if ($code === 'ALL' && $manual > 0 && self::mode() === self::MODE_MANUAL) {
            return $manual;
        }

        $rate = self::rates()[$code] ?? null;
        if ($rate === null && $code === 'ALL') {
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

    /** Fetch today's rates and store them platform-wide. Returns how many were stored. */
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

        PlatformSetting::set('currencies.rates', $tracked, 'json');
        PlatformSetting::set('currencies.updated_at', now()->toDateTimeString(), 'text');

        return count($tracked);
    }
}
