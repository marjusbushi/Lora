<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelMapping;
use App\Models\RoomType;
use App\Services\ChannelSync;
use App\Services\ChannexClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push one room type's availability + rates to Channex, off the request cycle.
 * Dispatched in real time when a reservation or a price changes. Idempotent —
 * it re-sends the current PMS truth, so duplicate dispatches are harmless.
 *
 * A reservation event only touches the nights it occupies, so it dispatches a
 * NARROW [$from, $to] window (Y-m-d strings — queue-serializable) and we push
 * only those dates. Range-less callers (pricing save, Sync-now, channex:push-ari)
 * leave both null and fall back to the full default window.
 */
class PushRoomTypeAri implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    /** Channex recommends waiting at least one minute after an API error/429. */
    public int $backoff = 60;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $roomTypeId,
        public ?string $from = null,
        public ?string $to = null,
    ) {
        $this->captureTenant();
    }

    public function handle(ChannelSync $sync): void
    {
        $roomType = RoomType::find($this->roomTypeId);
        if ($roomType) {
            // pushRoomType throws on a rejected push -> the queue retries (tries=3);
            // a no-op skip (unconfigured/unmapped) returns false without throwing.
            // Null from/to => pushRoomType uses its full default window.
            $sync->pushRoomType(
                $roomType,
                $this->from ? CarbonImmutable::parse($this->from) : null,
                $this->to ? CarbonImmutable::parse($this->to) : null,
            );
        }
    }

    /** All retries exhausted — surface it (don't let a failed sync stay buried in a log row). */
    public function failed(\Throwable $e): void
    {
        report($e);
    }

    /**
     * Queue a push for every Channex-mapped room type (no-op if Channex is not
     * configured). Returns how many were queued. Shared by the pricing save, the
     * Sync-now button, and the channex:push-ari command.
     */
    public static function dispatchAllMapped(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
    ): int {
        if (! app(ChannexClient::class)->configured()) {
            return 0;
        }

        $fromDate = $from ? CarbonImmutable::parse($from)->toDateString() : null;
        $toDate = $to ? CarbonImmutable::parse($to)->toDateString() : null;
        $ids = ChannelMapping::where('channel', 'channex')->pluck('room_type_id');
        foreach ($ids as $id) {
            self::dispatch($id, $fromDate, $toDate);
        }

        return $ids->count();
    }
}
