<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Services\CurrencyRates;
use Illuminate\Console\Command;

/**
 * Daily currency fetch (scheduler 06:00). Clean no-op when the owner has the
 * module OFF or no API key — the Settings toggle is the single cost switch.
 */
class CurrencyFetchRates extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'currency:fetch-rates {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Fetch today\'s exchange rates for the tracked currencies (Settings → Monedhat)';

    public function handle(CurrencyRates $rates): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! CurrencyRates::enabled()) {
            $this->info('Currency rates are OFF (or no API key) — nothing fetched.');

            return self::SUCCESS;
        }

        $count = $rates->fetch();
        $this->info("Stored {$count} rates (base EUR) — ".implode(', ', array_keys(CurrencyRates::rates())));

        return self::SUCCESS;
    }
}
