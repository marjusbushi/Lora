<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\TenantSubscription;
use Illuminate\Validation\ValidationException;

/**
 * Monedha e faturimit TË PLATFORMËS — sa i paguan hoteli Lora-s.
 *
 * KJO NUK ËSHTË logjika e monedhave të hotelit. CurrencyRates::rate() lexon
 * cilësimet e VETË hotelit (mode automatic/manual, manual_rates, disabled) —
 * po ta përdorte faturimi i platformës, hoteli do të vendoste vetë me çfarë
 * kursi të paguante. Prandaj këtu lexohet VETËM tabela qendrore e platformës
 * (PlatformSetting: currencies.rates, bazë EUR), ose kursi fiks i kontratës.
 *
 * Çmimet e katalogut janë gjithmonë në cent EURO. Konvertimi ndodh një herë të
 * vetme — kur lëshohet fatura — dhe kursi ngrihet mbi të.
 */
class PlatformBillingCurrency
{
    /** Katalogu i moduleve autorohet në këtë monedhë; gjithçka tjetër del prej saj. */
    public const BASE = 'EUR';

    /** Rrumbullakim në 10 njësi (10 lekë = 1000 cent) — vendim i pronarit, 2026-08-18. */
    public const ROUNDING_STEP_CENTS = 1000;

    /** @return list<string> monedhat në të cilat lejohet të faturohet një hotel */
    public function allowed(): array
    {
        $configured = config('lora_modules.billing_currencies', [self::BASE, 'ALL']);
        $codes = array_map(strtoupper(...), array_filter((array) $configured, 'is_string'));

        // Baza është gjithmonë e mundur, edhe nëse konfigurimi harron ta listojë.
        return array_values(array_unique([self::BASE, ...$codes]));
    }

    /**
     * Kursi për një abonim: kursi fiks i kontratës fiton mbi kursin ditor të
     * platformës. Baza kthen gjithmonë 1.0 (asnjë konvertim).
     *
     * @throws ValidationException kur monedha s'është bazë dhe s'ka asnjë kurs
     */
    public function rateFor(?TenantSubscription $subscription, string $currency): float
    {
        $currency = strtoupper($currency);

        if ($currency === self::BASE) {
            return 1.0;
        }

        $override = $subscription?->fx_rate_override;
        if ($override !== null && (float) $override > 0) {
            return (float) $override;
        }

        $rate = (float) (($this->platformRates()[$currency] ?? 0));

        if ($rate <= 0) {
            throw ValidationException::withMessages([
                'currency' => "Kursi për {$currency} mungon — sinkronizo kurset te Monedhat, "
                    .'ose vendos një kurs fiks te abonimi i hotelit.',
            ]);
        }

        return $rate;
    }

    /**
     * Cent bazë (euro) → cent të monedhës së faturimit, të rrumbullakosur në
     * hapin e mësipërm. Rrumbullakimi bëhet PËR RRESHT dhe totali është shuma e
     * rreshtave — ndryshe fatura nuk do të mblidhej me sytë e klientit.
     *
     * Një rresht me vlerë > 0 nuk bie kurrë në 0: minimumi është një hap.
     */
    public function convertCents(int $baseCents, float $rate): int
    {
        if ($rate === 1.0) {
            return $baseCents;
        }

        $converted = $baseCents * $rate;
        $rounded = (int) (round($converted / self::ROUNDING_STEP_CENTS) * self::ROUNDING_STEP_CENTS);

        if ($rounded === 0 && $baseCents > 0) {
            return self::ROUNDING_STEP_CENTS;
        }

        return $rounded;
    }

    /**
     * Cent të monedhës së faturimit → cent BAZË (euro), me kursin e NGRIRË të
     * vetë dokumentit.
     *
     * Pa këtë, çdo total i platformës do të mblidhte mollë me dardha: një rresht
     * prej €29 i ruajtur si 291 000 cent lekë do të shtonte €2 910 te KPI-të.
     */
    public function toBaseCents(int $cents, ?float $rate): int
    {
        if ($rate === null || $rate <= 0 || $rate === 1.0) {
            return $cents;
        }

        return (int) round($cents / $rate);
    }

    /**
     * Rrumbullakon një shumë TASHMË të konvertuar (p.sh. zbritjen, që del si
     * përqindje e nëntotalit) në të njëjtin hap si rreshtat.
     */
    public function roundToStep(int $cents): int
    {
        return (int) (round($cents / self::ROUNDING_STEP_CENTS) * self::ROUNDING_STEP_CENTS);
    }

    /** @return array<string,float> code => njësi për 1 EUR (vetëm tabela e platformës) */
    private function platformRates(): array
    {
        $rates = PlatformSetting::get('currencies.rates', null);

        if (! is_array($rates)) {
            return [];
        }

        $normalized = [];
        foreach ($rates as $code => $rate) {
            if (is_string($code) && is_numeric($rate)) {
                $normalized[strtoupper($code)] = (float) $rate;
            }
        }

        return $normalized;
    }
}
