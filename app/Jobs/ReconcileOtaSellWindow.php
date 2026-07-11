<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelMapping;
use App\Models\RoomType;
use App\Services\OtaSellWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Reconcile the latest owner-confirmed OTA cutoff across all mapped room types.
 * Old queued revisions are harmless: they exit before making an HTTP request.
 */
class ReconcileOtaSellWindow implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    /** Channex recommends waiting at least one minute after an API error/429. */
    public int $backoff = 60;

    public int $timeout = 30;

    public function __construct(
        public int $version,
        public string $target,
    ) {
        $this->captureTenant();
    }

    public function handle(OtaSellWindow $sellWindow): void
    {
        $target = CarbonImmutable::createFromFormat('!Y-m-d', $this->target);
        if ($sellWindow->version() !== $this->version
            || ! $sellWindow->effectiveUntil()->isSameDay($target)) {
            return;
        }

        $ids = ChannelMapping::query()
            ->where('channel', 'channex')
            ->whereNotNull('channex_room_type_id')
            ->distinct()
            ->pluck('room_type_id');

        $jobs = RoomType::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $roomTypeId) => new ReconcileOtaRoomType(
                $roomTypeId,
                $this->version,
                $target->toDateString(),
            ))
            ->all();

        // Each room type runs as its own short job. The finalizer is reached
        // only after every prior job succeeds. It waits for the next Channex
        // rate-limit window and for async ARI tasks before reading zero-tail back.
        $jobs[] = (new FinalizeOtaSellWindow($this->version, $target->toDateString()))
            ->delay(now()->addSeconds(65));

        Bus::chain($jobs)->dispatch();
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
