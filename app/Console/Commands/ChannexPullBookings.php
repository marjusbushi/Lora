<?php

namespace App\Console\Commands;

use App\Services\ChannexBookingImporter;
use App\Services\ChannexClient;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;

/**
 * Pull every unacknowledged Channex booking revision from the feed, import it
 * into the PMS, and acknowledge it. The catch-up / manual counterpart to the
 * real-time webhook (run it to backfill anything a missed webhook left behind).
 */
class ChannexPullBookings extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'channex:pull-bookings {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Pull + import unacknowledged Channex booking revisions (OTA -> PMS)';

    public function handle(ChannexClient $channex, ChannexBookingImporter $importer): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! $channex->configured()) {
            $this->error('CHANNEX_API_KEY is not set (.env).');

            return self::FAILURE;
        }

        // Without a property id the feed is account-wide and ownership of a
        // revision cannot be verified — running would risk draining OTHER
        // hotels' bookings. Refuse loudly instead.
        if ($channex->propertyId() === '') {
            $this->error('Channex property_id mungon për këtë hotel — plotësoje te integrimi para se të tërheqësh rezervime.');

            return self::FAILURE;
        }

        $feed = $channex->getBookingFeed();
        if ($feed === []) {
            $this->info('No unacknowledged bookings.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $cancelled = 0;
        foreach ($feed as $item) {
            try {
                $s = $importer->importRevision($item, $channex->propertyId());

                // Never ack a revision that belongs to another property (= another
                // hotel/tenant) — acking it would permanently hide the booking from
                // its real owner. The feed is property-filtered, so this is a
                // defense-in-depth backstop.
                if (($s['status'] ?? null) === 'foreign_property') {
                    $this->warn('  skipped foreign-property revision '.($item['id'] ?? '?').' (not acked)');

                    continue;
                }

                $channex->ackBookingRevision($item['id']);
                $created += $s['created'];
                $updated += $s['updated'];
                $cancelled += $s['cancelled'];
                $flag = $s['flagged'] ? '  [FLAG: '.implode('; ', $s['flagged']).']' : '';
                $this->line(sprintf('  %-12s %-18s +%d ~%d x%d%s', $s['channel'], $s['ref'], $s['created'], $s['updated'], $s['cancelled'], $flag));
            } catch (\Throwable $e) {
                $this->error('  failed '.($item['id'] ?? '?').' — '.$e->getMessage());
            }
        }
        $this->info("Done: {$created} new, {$updated} updated, {$cancelled} cancelled.");

        return self::SUCCESS;
    }
}
