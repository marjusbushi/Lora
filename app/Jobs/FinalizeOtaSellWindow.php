<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelMapping;
use App\Services\ChannelSync;
use App\Services\ChannexClient;
use App\Services\OtaSellWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** Read availability and rates back from Channex, then mark the revision delivered. */
class FinalizeOtaSellWindow implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $version,
        public string $target,
    ) {
        $this->captureTenant();
    }

    public function handle(
        OtaSellWindow $sellWindow,
        ChannexClient $channex,
        ChannelSync $sync,
    ): void {
        $target = CarbonImmutable::createFromFormat('!Y-m-d', $this->target);
        if ($sellWindow->version() !== $this->version
            || ! $sellWindow->effectiveUntil()->isSameDay($target)) {
            return;
        }

        $today = $sellWindow->today();
        // Verify the whole rolling Channex inventory table. Otherwise the new
        // edge date that appears each day could escape a fixed cutoff.
        $horizon = $sellWindow->maxUntil();
        $sellThrough = $target->min($horizon);
        $mappings = ChannelMapping::query()
            ->where('channel', 'channex')
            ->whereNotNull('channex_room_type_id')
            ->whereHas('roomType')
            ->with('roomType')
            ->orderBy('room_type_id')
            ->get();

        if ($today->lte($horizon) && $mappings->isNotEmpty()) {
            $remoteAvailability = $channex->getAvailabilityRange($today, $horizon);
            $remoteRates = $sellThrough->gte($today) && $mappings->contains('channex_rate_plan_id', '!=', null)
                ? $channex->getRateRange($today, $sellThrough)
                : [];

            foreach ($mappings as $mapping) {
                $expectedAvailability = $sellThrough->gte($today)
                    ? $sync->availabilityByDate($mapping->roomType, $today, $sellThrough)
                    : [];
                $remoteByDate = $remoteAvailability[$mapping->channex_room_type_id] ?? [];

                for ($date = $today; $date->lte($horizon); $date = $date->addDay()) {
                    $day = $date->toDateString();
                    $expected = $date->lte($sellThrough) ? $expectedAvailability[$day] : 0;
                    $actual = $this->strictInteger($remoteByDate[$day] ?? null);
                    if (! array_key_exists($day, $remoteByDate) || $actual !== $expected) {
                        throw new RuntimeException("Channex availability is not verified for {$mapping->channex_room_type_id} on {$day}.");
                    }
                }

                if (! $mapping->channex_rate_plan_id || $sellThrough->lt($today)) {
                    continue;
                }

                $expectedRates = $sync->priceByDate($mapping->roomType, $today, $sellThrough);
                $remoteRateByDate = $remoteRates[$mapping->channex_rate_plan_id] ?? [];
                foreach ($expectedRates as $day => $expectedRate) {
                    $actualRate = $remoteRateByDate[$day]['rate'] ?? null;
                    if (! is_numeric($actualRate) || abs((float) $actualRate - $expectedRate) > 0.009) {
                        throw new RuntimeException("Channex rate is not verified for {$mapping->channex_rate_plan_id} on {$day}.");
                    }
                }
            }
        }

        $sellWindow->markAppliedIfCurrent($this->version, $target);
    }

    private function strictInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
