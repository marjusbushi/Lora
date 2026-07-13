<?php

namespace App\Services;

use App\Models\ChannelMapping;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Computes the PMS truth (free rooms per date + seasonal price per date) for a
 * room type and pushes it to Channel manager (Channex) as availability + rates.
 * Consecutive dates that share a value are merged into one range so a flat year
 * is a couple of API rows, not 365. No-op when Channex is unconfigured or the
 * room type has no channel mapping.
 */
class ChannelSync
{
    /** Default sync window: today .. today + this many days (inclusive). */
    public const WINDOW_DAYS = 365;

    /**
     * Channex rate plan titles per role. The base plan carries the canonical
     * PMS price; the per-channel plans carry the program-compensated price
     * (see pushRatesForMapping). bootstrap-rooms creates them by these titles
     * and link-rooms classifies existing plans by them — keep in sync.
     */
    public const RATE_PLAN_TITLE_BASE = 'Standard Rate';

    public const RATE_PLAN_TITLE_BOOKING = 'Standard Rate - Booking.com';

    public const RATE_PLAN_TITLE_EXPEDIA = 'Standard Rate - Expedia';

    public function __construct(
        protected ChannexClient $channex,
        protected OtaSellWindow $sellWindow,
    ) {}

    /**
     * Push availability + rates for one room type to Channex over [from, to]
     * (inclusive; defaults to today .. today+WINDOW_DAYS). Returns false and does
     * nothing if Channex is not configured or this room type is not mapped.
     */
    public function pushRoomType(RoomType $roomType, ?CarbonInterface $from = null, ?CarbonInterface $to = null): bool
    {
        if (! $this->channex->configured()) {
            return false;
        }

        $mapping = ChannelMapping::where('channel', 'channex')
            ->where('room_type_id', $roomType->id)
            ->first();
        if (! $mapping || ! $mapping->channex_room_type_id) {
            return false;
        }

        return $this->sellWindow->withAriLock(function () use ($roomType, $mapping, $from, $to) {
            // Resolve the cutoff only after taking the same mutex used by every
            // ARI writer and sell-window change. This prevents a queued, stale
            // incremental push from reopening a date just closed by the owner.
            [$requestedFrom, $requestedTo] = $this->sellWindow->requestedRange($from, $to);
            $range = $this->sellWindow->clamp($from, $to);
            $availability = [];
            $publishedThrough = null;
            if ($range !== null) {
                [$effectiveFrom, $effectiveTo] = $range;
                $availability = $this->availabilityByDate($roomType, $effectiveFrom, $effectiveTo);
                $publishedThrough = $effectiveTo;
            }

            // Channex may auto-adjust availability after an OTA modification
            // or cancellation. For explicit reservation ranges, immediately
            // re-close any requested nights beyond the owner's fixed cutoff.
            if ($from !== null || $to !== null) {
                $closeFrom = $requestedFrom
                    ->max($this->sellWindow->today())
                    ->max($this->sellWindow->effectiveUntil()->addDay());
                // Re-close every requested night that still exists in the
                // current Channex inventory table, even if PMS had not
                // previously recorded that date as published.
                $closeTo = $requestedTo->min($this->sellWindow->maxUntil());
                for ($date = $closeFrom; $date->lte($closeTo); $date = $date->addDay()) {
                    $availability[$date->toDateString()] = 0;
                    $publishedThrough = $date;
                }
            }

            if ($availability === []) {
                return true;
            }

            $ok = $this->channex->pushAvailabilityRanges(
                $mapping->channex_room_type_id,
                $this->toRanges($availability, 'availability'),
                pmsRoomTypeId: $roomType->id,
            );

            if ($range !== null) {
                $ok = $this->pushRatesForMapping($mapping, $roomType, $effectiveFrom, $effectiveTo) && $ok;
            }

            // A rejected push (4xx / exhausted 5xx) must SURFACE so the queued
            // job retries; stale availability on an OTA risks overbooking.
            if (! $ok) {
                throw new RuntimeException("Channex push failed for room type {$roomType->id}");
            }

            if ($publishedThrough) {
                $this->sellWindow->rememberPublishedThrough($publishedThrough);
            }

            return true;
        });
    }

    /**
     * Apply one sell-window revision for one room type.
     *
     * Availability truth through the target and explicit zeroes after it are
     * combined in ONE Channex availability request. Rates are refreshed only
     * through the target. We deliberately never send stop_sell: availability=0
     * closes the removed dates without overwriting independent restrictions.
     *
     * @return bool|null true=applied, false=unconfigured/unmapped, null=stale
     */
    public function reconcileRoomType(RoomType $roomType, int $expectedVersion, CarbonInterface $expectedTarget): ?bool
    {
        if (! $this->channex->configured()) {
            return false;
        }

        $mapping = ChannelMapping::where('channel', 'channex')
            ->where('room_type_id', $roomType->id)
            ->first();
        if (! $mapping || ! $mapping->channex_room_type_id) {
            return false;
        }

        return $this->sellWindow->withAriLock(function () use ($roomType, $mapping, $expectedVersion, $expectedTarget) {
            $target = $this->sellWindow->effectiveUntil();
            if ($this->sellWindow->version() !== $expectedVersion || ! $target->isSameDay($expectedTarget)) {
                return null;
            }

            $today = $this->sellWindow->today();
            // Inventory Days is a rolling table: one new future date enters it
            // every day. Reconcile the whole current table so a fixed cutoff
            // remains fixed without relying on Channex's defaults for that new
            // edge date.
            $horizon = $this->sellWindow->maxUntil();
            $sellThrough = $target->min($horizon);
            $availability = $sellThrough->gte($today)
                ? $this->availabilityByDate($roomType, $today, $sellThrough)
                : [];

            // A fixed cutoff eventually becomes a past date. Channex only needs
            // current/future dates closed, so never send a past ARI date.
            $closeFrom = $target->addDay()->max($today);
            for ($date = $closeFrom; $date->lte($horizon); $date = $date->addDay()) {
                $availability[$date->toDateString()] = 0;
            }

            $ok = $this->channex->pushAvailabilityRanges(
                $mapping->channex_room_type_id,
                $this->toRanges($availability, 'availability'),
                pmsRoomTypeId: $roomType->id,
            );

            if ($sellThrough->gte($today)) {
                $ok = $this->pushRatesForMapping($mapping, $roomType, $today, $sellThrough) && $ok;
            }

            if (! $ok) {
                throw new RuntimeException("Channex sell-window reconciliation failed for room type {$roomType->id}");
            }

            $this->sellWindow->rememberPublishedThrough($horizon);

            return true;
        });
    }

    /**
     * Free rooms per date = (rooms of this type, excluding maintenance) minus the
     * active reservations whose stay covers that date. Floored at 0. A reservation
     * occupies [check_in, check_out) — the check-out day is free (matches
     * Reservation::isRoomAvailable).
     *
     * @return array<string,int> 'Y-m-d' => available
     */
    public function availabilityByDate(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): array
    {
        $roomIds = Room::where('room_type_id', $roomType->id)
            ->where('status', '!=', 'maintenance')
            ->pluck('id');
        $sellable = $roomIds->count();

        // whereDate (not raw <=) so the comparison is date-only: a date column can
        // be stored with a time component (sqlite), and 'YYYY-MM-DD 00:00:00' sorts
        // AFTER 'YYYY-MM-DD' as a string, which would silently drop a same-day
        // check-in. The per-date filter below is the precise overlap check.
        $reservations = $roomIds->isEmpty() ? collect() : Reservation::whereIn('room_id', $roomIds)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_in_date', '<=', $to->toDateString())
            ->whereDate('check_out_date', '>', $from->toDateString())
            ->get(['check_in_date', 'check_out_date'])
            ->map(fn ($r) => [
                'in' => $r->check_in_date->toDateString(),
                'out' => $r->check_out_date->toDateString(),
            ]);

        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->lte($to); $d = $d->addDay()) {
            $date = $d->toDateString();
            $occupied = $reservations->filter(fn ($r) => $date >= $r['in'] && $date < $r['out'])->count();
            $out[$date] = max(0, $sellable - $occupied);
        }

        return $out;
    }

    /**
     * Push nightly rates over [from, to] to every mapped rate plan of this room
     * type: the BASE plan gets the canonical PMS price; the per-channel plans
     * (Booking.com / Expedia) get the price divided by that channel's discount
     * factor from OtaPricingPrograms — so after the OTA applies its member/
     * mobile promotions, the guest sees the PMS price again. A factor of 1
     * (no programs enabled) pushes the base price unchanged; an unmapped
     * channel plan is skipped (single-plan behaviour is preserved).
     */
    protected function pushRatesForMapping(ChannelMapping $mapping, RoomType $roomType, CarbonInterface $from, CarbonInterface $to): bool
    {
        $prices = $this->priceByDate($roomType, $from, $to);
        $programs = OtaPricingPrograms::settings();

        $plans = [
            [$mapping->channex_rate_plan_id, 1.0],
            [$mapping->channex_booking_rate_plan_id, (float) $programs['booking']['discount_factor']],
            [$mapping->channex_expedia_rate_plan_id, (float) $programs['expedia']['discount_factor']],
        ];

        $ok = true;
        foreach ($plans as [$planId, $factor]) {
            if (! $planId) {
                continue;
            }
            $adjusted = abs($factor - 1.0) < 1e-9
                ? $prices
                : array_map(fn (float $p) => round($p / $factor, 2), $prices);

            $ok = $this->channex->pushRateRanges(
                $planId,
                $this->toRanges($adjusted, 'rate'),
                pmsRoomTypeId: $roomType->id,
            ) && $ok;
        }

        return $ok;
    }

    /**
     * Nightly price per date from RoomPricing (seasons + base). quote() is
     * half-open, so quote [from, to+1] to get a price for every inclusive day.
     *
     * @return array<string,float> 'Y-m-d' => price
     */
    public function priceByDate(RoomType $roomType, CarbonInterface $from, CarbonInterface $to): array
    {
        $breakdown = RoomPricing::quote($roomType, $from->toDateString(), $to->addDay()->toDateString())['breakdown'];

        $out = [];
        foreach ($breakdown as $night) {
            $out[$night['date']] = (float) $night['price'];
        }

        return $out;
    }

    /**
     * Collapse an ordered date=>value map into Channex inclusive date ranges,
     * merging runs of consecutive days that share a value.
     *
     * @param  array<string,int|float>  $byDate
     * @return array<int,array<string,string|int|float>>
     */
    protected function toRanges(array $byDate, string $field): array
    {
        $ranges = [];
        $start = $prevDate = null;
        $prevVal = null;

        foreach ($byDate as $date => $val) {
            if ($start === null) {
                $start = $prevDate = $date;
                $prevVal = $val;

                continue;
            }
            $isNextDay = CarbonImmutable::parse($prevDate)->addDay()->toDateString() === $date;
            if ($val !== $prevVal || ! $isNextDay) {
                $ranges[] = ['date_from' => $start, 'date_to' => $prevDate, $field => $prevVal];
                $start = $date;
            }
            $prevDate = $date;
            $prevVal = $val;
        }
        if ($start !== null) {
            $ranges[] = ['date_from' => $start, 'date_to' => $prevDate, $field => $prevVal];
        }

        return $ranges;
    }
}
