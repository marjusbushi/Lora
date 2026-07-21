<?php

namespace App\Services;

use App\Models\RoomType;
use Carbon\Carbon;

/**
 * Thin wrapper around PricingEngine (Copa 2 replaced the old static occupancy
 * bands with the deterministic factor pipeline). Kept as the stable facade the
 * controller/tests talk to; the math lives in PricingEngine. Suggest-only:
 * nothing changes until the owner accepts (which writes a RateOverride).
 */
class SmartPricing
{
    public static function settings(): array
    {
        return [
            'strategy' => PricingEngine::strategy(),
        ];
    }

    /**
     * Actionable suggestions across the horizon, all room types (legacy shape).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function suggestions(?int $days = null): array
    {
        $days = $days ?? 60;
        $from = Carbon::today()->addDay();
        $to = Carbon::today()->addDays($days - 1);

        $rows = [];
        foreach (RoomType::all() as $type) {
            foreach (PricingEngine::forRange($type, $from, $to) as $day) {
                if (! $day['actionable']) {
                    continue;
                }
                $rows[] = [
                    'date' => $day['date'],
                    'room_type_id' => $type->id,
                    'room_type_name' => $type->name,
                    'total' => $day['total'],
                    'booked' => $day['booked'],
                    'occupancy_pct' => $day['occupancy_pct'],
                    'current_price' => $day['current_price'],
                    'calculated_price' => $day['calculated_price'],
                    'guarded_price' => $day['guarded_price'],
                    'suggested_price' => $day['suggested_price'],
                    'adjustment_pct' => $day['adjustment_pct'],
                    'factors' => $day['factors'],
                    'clamped' => $day['clamped'],
                    'rounding' => $day['rounding'],
                    'has_override' => $day['has_override'],
                    'days_until' => $day['days_until'],
                    'ota_prices' => OtaPricingPrograms::quote((float) $day['suggested_price']),
                ];
            }
        }

        return $rows;
    }

    /**
     * One room type, one month: engine suggestion + presentation fields
     * (weekend/demand events) per date for the calendar view.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function calendar(RoomType $type, Carbon $from, Carbon $to): array
    {
        $engine = PricingEngine::forRange($type, $from, $to);

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $row = $engine[$d->toDateString()];
            $row['dow'] = (int) $d->dayOfWeekIso; // 1=Mon .. 7=Sun
            $row['is_weekend'] = in_array((int) $d->dayOfWeekIso, [5, 6], true); // Fri + Sat nights
            // One source of truth: the same persisted events feed both the
            // badge and the engine. Context-only events stay visible but do
            // not enter the factor breakdown.
            $row['holiday'] = collect($row['events'] ?? [])->pluck('name')->implode(' · ') ?: null;
            $days[] = OtaPricingPrograms::decorate($row);
        }

        return $days;
    }
}
