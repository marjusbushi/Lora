<?php

namespace App\Services;

use App\Models\CatalogPriceOverride;
use Illuminate\Support\Facades\Schema;

/**
 * Burimi i VETËM i katalogut të moduleve: struktura (emrat, përshkrimet,
 * billing_model) nga config/lora_modules, çmimet të mbivendosshme nga
 * catalog_price_overrides (platformë-global, editohet në panelin Lora).
 *
 * Vetëm fushat jo-NULL të override-it fitojnë mbi config. Nëse tabela ende
 * s'ekziston (dritarja e deploy-it — lesson #65), kthehet config-u i pastër
 * në vend të një 500-e.
 */
class ModuleCatalog
{
    public const PRICE_FIELDS = [
        'unit_price_cents',
        'first_unit_price_cents',
        'excess_unit_price_cents',
        'tier_limit',
        'percentage_bps',
    ];

    private static ?array $cached = null;

    /** @return array<string, array<string, mixed>> */
    public static function modules(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $modules = config('lora_modules.modules', []);

        $overrides = rescue(
            fn () => Schema::hasTable('catalog_price_overrides')
                ? CatalogPriceOverride::query()->get()->keyBy('module_code')
                : collect(),
            collect(),
            report: false,
        );

        foreach ($modules as $code => $definition) {
            $override = $overrides->get($code);
            if (!$override) {
                continue;
            }

            foreach (self::PRICE_FIELDS as $field) {
                if ($override->{$field} !== null) {
                    $modules[$code][$field] = (int) $override->{$field};
                }
            }
        }

        return self::$cached = $modules;
    }

    /** @return array<string, mixed>|null */
    public static function module(string $code): ?array
    {
        return self::modules()[$code] ?? null;
    }

    public static function flush(): void
    {
        self::$cached = null;
    }
}
