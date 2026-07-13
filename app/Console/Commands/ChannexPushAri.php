<?php

namespace App\Console\Commands;

use App\Jobs\PushRoomTypeAri;
use App\Jobs\ReconcileOtaSellWindow;
use App\Models\ChannelMapping;
use App\Models\RoomType;
use App\Services\ChannelSync;
use App\Services\ChannexClient;
use App\Services\OtaSellWindow;
use Carbon\CarbonImmutable;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;

/**
 * Full availability + rate sync for every Channex-mapped room type. Inline by
 * default (initial/manual sync with per-type output); --queue dispatches the
 * jobs instead.
 *
 *   php artisan channex:push-ari
 *   php artisan channex:push-ari --days=180 --queue
 */
class ChannexPushAri extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'channex:push-ari {--days=365 : Days ahead to sync} {--queue : Dispatch jobs instead of pushing inline} {--reconcile-fixed : Re-apply and verify a configured fixed OTA cutoff} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Push availability + rates for all Channex-mapped room types';

    public function handle(ChannelSync $sync, OtaSellWindow $sellWindow, ChannexClient $channex): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! $channex->configured()) {
            $this->error('Channex is not configured for this hotel.');

            return self::FAILURE;
        }

        $ids = ChannelMapping::where('channel', 'channex')->pluck('room_type_id');
        if ($ids->isEmpty()) {
            $this->warn('No Channex-mapped room types — run channex:link-rooms first.');

            return self::SUCCESS;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($days === false) {
            $this->error('--days must be a non-negative integer.');

            return self::FAILURE;
        }

        $from = CarbonImmutable::today();
        $to = $from->addDays($days);

        if ($this->option('queue')) {
            if ($this->option('reconcile-fixed') && $sellWindow->configuredUntil()) {
                ReconcileOtaSellWindow::dispatch(
                    $sellWindow->version(),
                    $sellWindow->effectiveUntil()->toDateString(),
                );
                $this->info('Queued fixed OTA sell-window reconciliation.');

                return self::SUCCESS;
            }

            $count = PushRoomTypeAri::dispatchAllMapped($from, $to);
            $this->info("Queued {$count} room type push(es).");

            return self::SUCCESS;
        }

        $failed = false;
        foreach (RoomType::whereIn('id', $ids)->orderBy('id')->get() as $roomType) {
            try {
                $ok = $sync->pushRoomType($roomType, $from, $to);
                $this->line(($ok ? '  OK   ' : '  SKIP ').$roomType->name);
            } catch (\Throwable $e) {
                $failed = true;
                $this->line('  FAIL '.$roomType->name.' — '.$e->getMessage());
            }
        }
        $this->info($failed ? 'Done with failures.' : 'Done.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
