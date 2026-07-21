<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The commercial currency used by room rates, Smart Pricing, the direct
 * website and channel-manager ARI. It is deliberately independent from the
 * tenant's accounting/base currency.
 */
class PricingCurrency
{
    public static function code(): string
    {
        $code = strtoupper((string) Setting::get('pricing.currency', BaseCurrency::code()));

        return in_array($code, config('lora.tenant_currencies', []), true)
            ? $code
            : BaseCurrency::code();
    }

    public static function symbol(): string
    {
        return BaseCurrency::symbol(self::code());
    }
}
