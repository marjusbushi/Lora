<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use App\Models\RoomType;
use App\Services\ChannelSync;
use App\Services\OtaSellWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** Apply one revision to one mapped room type in a short, retryable job. */
class ReconcileOtaRoomType implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $roomTypeId,
        public int $version,
        public string $target,
    ) {
        $this->captureTenant();
    }

    public function handle(ChannelSync $sync, OtaSellWindow $sellWindow): void
    {
        $target = CarbonImmutable::createFromFormat('!Y-m-d', $this->target);
        if ($sellWindow->version() !== $this->version
            || ! $sellWindow->effectiveUntil()->isSameDay($target)) {
            return;
        }

        $roomType = RoomType::find($this->roomTypeId);
        if (! $roomType) {
            return;
        }

        if ($sync->reconcileRoomType($roomType, $this->version, $target) === false) {
            throw new RuntimeException("Channex reconciliation skipped for mapped room type {$roomType->id}.");
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
