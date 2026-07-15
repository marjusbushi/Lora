<?php

namespace App\Services;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The tenant's functional currency. Every operational amount is expressed in
 * this currency unless a document explicitly freezes a foreign-currency rate.
 */
class BaseCurrency
{
    public static function code(): string
    {
        $tenant = app(TenantContext::class)->tenant();
        $code = strtoupper((string) ($tenant?->currency ?: 'EUR'));

        return in_array($code, config('lora.tenant_currencies', []), true) ? $code : 'EUR';
    }

    public static function symbol(?string $currency = null): string
    {
        return match (strtoupper($currency ?: self::code())) {
            'EUR' => '€',
            'ALL' => 'L',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            'TRY' => '₺',
            'CAD' => 'CA$',
            'AUD' => 'A$',
            'SEK' => 'kr',
            'NOK' => 'kr',
            default => strtoupper($currency ?: self::code()),
        };
    }

    /** Quote-currency units per one unit of the tenant base currency. */
    public static function rate(string $quoteCurrency): ?float
    {
        return CurrencyRates::between(self::code(), $quoteCurrency);
    }

    /** @return array<string,float> */
    public static function rates(): array
    {
        return collect(config('lora.tenant_currencies', []))
            ->mapWithKeys(fn (string $currency) => [$currency => self::rate($currency)])
            ->filter(fn ($rate) => $rate !== null)
            ->all();
    }

    /**
     * A functional-currency change after monetary activity would rewrite the
     * meaning of every stored amount. Require an explicit migration instead.
     */
    public static function assertCanChange(Tenant $tenant, string $newCurrency): void
    {
        if (strtoupper((string) $tenant->currency) === strtoupper($newCurrency)) {
            return;
        }

        foreach (['finance_payments', 'bills', 'invoices', 'payments', 'pos_orders', 'reservations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('tenant_id', $tenant->id)->exists()) {
                throw ValidationException::withMessages([
                    'currency' => 'Monedha bazë nuk mund të ndryshohet pasi hoteli ka transaksione. Kërkohet migrim financiar i kontrolluar.',
                ]);
            }
        }
    }
}
