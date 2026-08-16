<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Turns a mathematically calculated hotel rate into a guest-facing commercial
 * price without ever crossing the owner's min/max guardrails.
 */
class CommercialPriceRounding
{
    public const MODE_COMMERCIAL = 'commercial';

    public const MODE_EXACT = 'exact';

    public static function mode(): string
    {
        $mode = (string) Setting::get('pricing.rounding_mode', self::MODE_COMMERCIAL);

        return in_array($mode, [self::MODE_COMMERCIAL, self::MODE_EXACT], true)
            ? $mode
            : self::MODE_COMMERCIAL;
    }

    /** @return array{mode:string,currency:string,label:string} */
    public static function policy(?string $currency = null, ?string $mode = null): array
    {
        $currency = strtoupper($currency ?: PricingCurrency::code());
        $mode ??= self::mode();

        return [
            'mode' => $mode,
            'currency' => $currency,
            'label' => match (true) {
                $mode === self::MODE_EXACT => 'Exact prices',
                $currency === 'ALL' => '100 ALL steps',
                default => '5/9 endings below 100 · steps of 5 from 100+',
            },
        ];
    }

    /**
     * @return array{mode:string,currency:string,rule:string,before:float,after:float,applied:bool}
     */
    public static function apply(
        float $price,
        ?float $min = null,
        ?float $max = null,
        ?string $currency = null,
        ?string $mode = null,
        ?float $reference = null,
    ): array {
        $currency = strtoupper($currency ?: PricingCurrency::code());
        $mode ??= self::mode();
        $guarded = self::clamp($price, $min, $max);

        if ($mode === self::MODE_EXACT) {
            $rounded = round($guarded, 2);
        } else {
            $rounded = self::commercial($guarded, $currency);

            if (! self::inside($rounded, $min, $max)
                || ! self::preservesDirection($guarded, $rounded, $reference)) {
                $rounded = self::closestAllowedInside($guarded, $currency, $min, $max, $reference)
                    ?? round($guarded, 2);
            }
        }

        return [
            'mode' => $mode,
            'currency' => $currency,
            'rule' => $mode === self::MODE_EXACT
                ? 'exact'
                : ($currency === 'ALL' ? 'step_100' : 'endings_5_9_then_step_5'),
            'before' => round($guarded, 2),
            'after' => round($rounded, 2),
            'applied' => abs($rounded - $guarded) >= 0.005,
        ];
    }

    private static function commercial(float $price, string $currency): float
    {
        if ($currency === 'ALL') {
            return round($price / 100, 0, PHP_ROUND_HALF_UP) * 100;
        }

        if ($price >= 100) {
            return round($price / 5, 0, PHP_ROUND_HALF_UP) * 5;
        }

        return self::nearest($price, self::subHundredCandidates());
    }

    private static function clamp(float $price, ?float $min, ?float $max): float
    {
        if ($max !== null) {
            $price = min($price, $max);
        }
        if ($min !== null) {
            $price = max($price, $min);
        }

        return $price;
    }

    private static function inside(float $price, ?float $min, ?float $max): bool
    {
        return ($min === null || $price >= $min - 0.0001)
            && ($max === null || $price <= $max + 0.0001);
    }

    private static function closestAllowedInside(
        float $price,
        string $currency,
        ?float $min,
        ?float $max,
        ?float $reference = null,
    ): ?float {
        $candidates = $currency === 'ALL'
            ? self::stepCandidates($price, $min, $max, 100, 100)
            : array_merge(
                self::subHundredCandidates(),
                self::stepCandidates($price, $min, $max, 5, 100),
            );

        $allowed = array_values(array_filter(
            array_unique($candidates),
            fn ($candidate) => self::inside((float) $candidate, $min, $max)
                && self::preservesDirection($price, (float) $candidate, $reference),
        ));

        return $allowed === [] ? null : self::nearest($price, $allowed);
    }

    /** Commercial presentation must never turn an increase into a decrease, or vice versa. */
    private static function preservesDirection(float $price, float $candidate, ?float $reference): bool
    {
        if ($reference === null) {
            return true;
        }

        $direction = $price <=> $reference;

        return match ($direction) {
            1 => $candidate >= $reference - 0.0001,
            -1 => $candidate <= $reference + 0.0001,
            default => abs($candidate - $reference) < 0.0001,
        };
    }

    /** @return list<float> */
    private static function subHundredCandidates(): array
    {
        $candidates = [];
        for ($decade = 0; $decade < 100; $decade += 10) {
            foreach ([5, 9] as $ending) {
                $candidate = $decade + $ending;
                if ($candidate < 100) {
                    $candidates[] = (float) $candidate;
                }
            }
        }

        return $candidates;
    }

    /** @return list<float> */
    private static function stepCandidates(float $price, ?float $min, ?float $max, float $step, float $floor): array
    {
        $candidates = [];
        foreach ([$price, $min, $max] as $anchor) {
            if ($anchor === null) {
                continue;
            }
            $base = floor($anchor / $step) * $step;
            for ($offset = -2; $offset <= 2; $offset++) {
                $candidate = $base + $offset * $step;
                if ($candidate >= $floor) {
                    $candidates[] = (float) $candidate;
                }
            }
        }

        return $candidates;
    }

    /** Ties deliberately go up: 77 → 79 and 102.5 → 105. */
    private static function nearest(float $price, array $candidates): float
    {
        usort($candidates, function ($a, $b) use ($price) {
            $distance = abs($a - $price) <=> abs($b - $price);

            return $distance !== 0 ? $distance : $b <=> $a;
        });

        return (float) $candidates[0];
    }
}
