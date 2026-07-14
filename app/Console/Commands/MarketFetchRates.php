<?php

namespace App\Console\Commands;

use App\Services\MarketRates;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;

/**
 * Fetch one competitor-price snapshot (rate shopping, Phase 1). A clean no-op
 * when the owner has the feature OFF or no API key — the toggle in Settings
 * is the single cost switch. --scheduled additionally respects the configured
 * frequency (3x_week skips off-days); a manual run always fetches.
 *
 *   php artisan market:fetch-rates            # manual snapshot, 30 days
 *   php artisan market:fetch-rates --days=45
 */
class MarketFetchRates extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'market:fetch-rates
        {--days=30 : Stay dates ahead to fetch}
        {--scheduled : Invoked by the scheduler — respect the configured frequency}
        {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Fetch competitor nightly prices for the comp-set into comp_rates (owner-controlled, Settings)';

    public function handle(MarketRates $market): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! MarketRates::enabled()) {
            $this->info('Market rates are OFF (or no API key) — nothing fetched. Enable in Settings → Çmimet e Tregut.');

            return self::SUCCESS;
        }

        if ($this->option('scheduled') && ! MarketRates::shouldRunToday()) {
            $this->info('Skipped: frequency is '.MarketRates::frequency().' and today is an off-day.');

            return self::SUCCESS;
        }

        $days = max(1, min(60, (int) $this->option('days')));
        $summary = $market->fetchSnapshot($days);

        foreach ($summary['matched'] as $competitor => $count) {
            $this->line(sprintf('  %-34s %d date(s)', $competitor, $count));
        }
        $this->info(sprintf(
            'Snapshot done: %d/%d dates fetched, %d prices stored, %d failed request(s).',
            $summary['dates'],
            $days,
            $summary['rows'],
            $summary['failed'],
        ));
        if ($summary['dates'] > 0 && $summary['matched'] === []) {
            $this->warn('No competitor matched — check the names in Settings against their names on Google Hotels.');
        }

        return self::SUCCESS;
    }
}
