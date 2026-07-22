<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Daily exchange rates for the 10 tracked currencies (EUR base), fetched from
 * ExchangeRate-API v6 ONCE per day FOR THE WHOLE PLATFORM (scheduler) or on
 * demand from the super-admin control panel. Hotels choose a rate source:
 * automatic (platform-synced, read-only) or manual (their own required rate
 * per enabled currency). Rates are read at WRITE time and frozen onto
 * documents — a new day's rate never rewrites yesterday's bills/payments.
 */
class CurrencyRates
{
    /** EUR is the base; these are the tracked quote currencies. */
    public const CURRENCIES = ['USD', 'GBP', 'ALL', 'CHF', 'TRY', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK'];

    /** Per-hotel rate mode: platform-synced, or the hotel's own manual rates. */
    public const MODE_AUTOMATIC = 'automatic';

    public const MODE_MANUAL = 'manual';

    /** Daily fetch cadence plus one hour of grace before the admin banner fires. */
    private const STALE_AFTER_HOURS = 25;

    /**
     * The hotel's chosen mode. Automatic (default): platform rates, manual
     * values only as emergency fallback. Manual: the hotel's own rates win
     * for every enabled currency.
     */
    public static function mode(): string
    {
        return Setting::get('currencies.mode') === self::MODE_MANUAL
            ? self::MODE_MANUAL
            : self::MODE_AUTOMATIC;
    }

    /**
     * The hotel's own rates (code => units per 1 EUR). The pre-refinement
     * single manual ALL rate (financial.fx_all_per_eur) keeps feeding ALL
     * until the hotel saves the new per-currency form.
     *
     * @return array<string,float>
     */
    public static function manualRates(): array
    {
        $rates = Setting::get('currencies.manual_rates', null);
        $rates = is_array($rates) ? array_map('floatval', $rates) : [];

        if (! isset($rates['ALL'])) {
            $legacy = (float) Setting::get('financial.fx_all_per_eur', 0);
            if ($legacy > 0) {
                $rates['ALL'] = $legacy;
            }
        }

        return array_filter($rates, fn ($rate) => $rate > 0);
    }

    /** @return list<string> tracked currencies this hotel switched off */
    public static function disabledCurrencies(): array
    {
        $disabled = Setting::get('currencies.disabled', null);

        if (! is_array($disabled)) {
            return [];
        }

        // Protected currencies can never be disabled, even by stale stored data.
        return array_values(array_diff(
            array_intersect(self::CURRENCIES, $disabled),
            self::protectedCurrencies(),
        ));
    }

    /**
     * Currencies this hotel can never disable — derived DYNAMICALLY from its
     * own settings (base/accounting currency + commercial pricing currency),
     * never hardcoded.
     *
     * @return list<string>
     */
    public static function protectedCurrencies(): array
    {
        return array_values(array_unique([BaseCurrency::code(), PricingCurrency::code()]));
    }

    /** @return list<string> tracked currencies the hotel still uses */
    public static function enabledCurrencies(): array
    {
        return array_values(array_diff(self::CURRENCIES, self::disabledCurrencies()));
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

    /** The last fetch failure ('' when the last fetch succeeded). */
    public static function lastError(): string
    {
        return (string) PlatformSetting::get('currencies.last_error', '');
    }

    public static function recordFetchFailure(string $message): void
    {
        PlatformSetting::set('currencies.last_error', now()->toDateTimeString().' — '.$message);
    }

    /**
     * Platform rates need admin attention: the integration is on but the last
     * fetch failed, never ran, or is older than the daily cadence allows.
     * Hotels meanwhile keep reading the LAST SYNCED table — nothing breaks,
     * but the admin must know invoices are using yesterday's rates.
     */
    public static function isStale(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if (self::lastError() !== '') {
            return true;
        }

        $updatedAt = PlatformSetting::get('currencies.updated_at');
        if (! $updatedAt) {
            return true;
        }

        return Carbon::parse($updatedAt)->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * Units of $code per 1 EUR, or null when unknown or disabled by the hotel.
     * Manual mode: the hotel's own rate wins for every enabled currency (the
     * platform serves any rate not yet entered). Automatic mode: the platform
     * table (last synced values persist through outages), with the hotel's
     * saved manual values as last-resort fallback.
     */
    public static function rate(string $code): ?float
    {
        $code = strtoupper($code);
        if ($code === 'EUR') {
            return 1.0;
        }

        if (in_array($code, self::disabledCurrencies(), true)) {
            return null;
        }

        $manual = self::manualRates();

        if (self::mode() === self::MODE_MANUAL && isset($manual[$code])) {
            return $manual[$code];
        }

        $rate = self::rates()[$code] ?? null;
        if ($rate === null && isset($manual[$code])) {
            $rate = $manual[$code];
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
        PlatformSetting::set('currencies.last_error', '', 'text');

        return count($tracked);
    }
}
