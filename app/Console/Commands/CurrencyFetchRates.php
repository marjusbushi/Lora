<?php

namespace App\Console\Commands;

use App\Services\CurrencyRates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily currency fetch (scheduler 06:00) — PLATFORM-WIDE: one API call per day
 * shared by every hotel, configured from the super-admin panel. Clean no-op
 * when the platform toggle is OFF or no API key — the single cost switch.
 */
class CurrencyFetchRates extends Command
{
    protected $signature = 'currency:fetch-rates';

    protected $description = 'Fetch today\'s exchange rates for the whole platform (Admin → Monedhat)';

    public function handle(CurrencyRates $rates): int
    {
        if (! CurrencyRates::enabled()) {
            $this->info('Platform currency rates are OFF (or no API key) — nothing fetched.');

            return self::SUCCESS;
        }

        try {
            $count = $rates->fetch();
        } catch (\Throwable $e) {
            Log::error('Platform currency fetch failed', ['error' => $e->getMessage()]);
            $this->error('Currency fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Stored {$count} platform rates (base EUR) — ".implode(', ', array_keys(CurrencyRates::rates())));

        return self::SUCCESS;
    }
}
