<?php

namespace App\Services;

use App\Models\PricingEvent;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Deterministic price suggestions — the ONE brain of Çmim Inteligjent 2.0.
 * suggested = seasonal reference × occupancy curve × pickup pace × lead time
 * × day-of-week × event uplift, clamped to the owner's per-type min/max.
 * Same inputs → same output; every price ships with a factor breakdown for
 * the "Pse ky çmim?" UI. NO LLM ever influences the number (ratified design).
 */
class PricingEngine
{
    /** Strategy presets scale the demand factors (never the owner's event uplifts). */
    private const STRATEGY = ['kujdesshem' => 0.6, 'balancuar' => 1.0, 'agresiv' => 1.4];

    /** Far-future empty days get no discount nag beyond this horizon. */
    private const DISCOUNT_HORIZON_DAYS = 14;

    public static function strategy(): string
    {
        $s = (string) Setting::get('pricing.strategy', 'balancuar');

        return array_key_exists($s, self::STRATEGY) ? $s : 'balancuar';
    }

    /**
     * Per-date suggestions for one room type across a range.
     * Loads everything once; per-date math is pure.
     *
     * @return array<string,array<string,mixed>> keyed by 'Y-m-d'
     */
    public static function forRange(RoomType $type, Carbon $from, Carbon $to): array
    {
        $today = Carbon::today();
        $mult = self::STRATEGY[self::strategy()];

        // Supply: this type + whole property (for pooling). Maintenance rooms
        // are not sellable, and reservations sitting on them are excluded from
        // occupancy too — consistent numerator/denominator (no >100%).
        $allRooms = Room::get(['id', 'room_type_id', 'status']);
        $serviceable = $allRooms->where('status', '!=', 'maintenance');
        $typeRoomIds = $serviceable->where('room_type_id', $type->id)->pluck('id');
        $propertyRoomIds = $serviceable->pluck('id');

        // Demand: ALL active reservations, property-wide (pending holds
        // inventory on purpose — an unpaid POK hold blocks the room until
        // pok:release-unpaid frees it). Occupancy later narrows to serviceable
        // rooms; the pace delta must stay all-rooms to match how the nightly
        // snapshot counts (else a room flipping to maintenance fakes a
        // "demand cooling" signal).
        $reservations = Reservation::whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_out_date', '>', $from->toDateString())
            ->whereDate('check_in_date', '<=', $to->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date']);

        $overrides = RateOverride::where('room_type_id', $type->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn ($o) => $o->date->toDateString());

        $events = PricingEvent::betweenDates($from, $to);

        // Pace baseline: the on-the-books picture ~7 days ago (property-level,
        // from the nightly snapshots). Earliest snapshot 3-7 days old wins;
        // younger history = no pace signal yet (deterministic given the DB).
        $baseline = self::paceBaseline($today, $from, $to);

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dateStr = $d->toDateString();
            $nightCovers = fn ($r) => $r->check_in_date?->toDateString() <= $dateStr
                && $r->check_out_date?->toDateString() > $dateStr;

            $covering = $reservations->filter($nightCovers);
            $bookedType = $covering->whereIn('room_id', $typeRoomIds)->pluck('room_id')->unique()->count();
            $bookedProperty = $covering->whereIn('room_id', $propertyRoomIds)->pluck('room_id')->unique()->count();
            // All-rooms count — same convention as the snapshot's booked column.
            $bookedAll = $covering->pluck('room_id')->unique()->count();

            $days[$dateStr] = self::compute($type, $d, [
                'today' => $today,
                'mult' => $mult,
                'type_total' => $typeRoomIds->count(),
                'type_booked' => min($bookedType, $typeRoomIds->count()),
                'property_total' => $propertyRoomIds->count(),
                'property_booked' => min($bookedProperty, $propertyRoomIds->count()),
                'property_booked_all' => $bookedAll,
                'override' => $overrides->get($dateStr),
                'events' => $events->filter(fn ($e) => $e->resolved_from->toDateString() <= $dateStr
                    && $e->resolved_to->toDateString() >= $dateStr),
                'baseline_booked' => $baseline['byStayDate'][$dateStr] ?? null,
                'baseline_span' => $baseline['spanDays'],
            ]);
        }

        return $days;
    }

    /**
     * The pure per-date computation. Returns the suggestion + factor breakdown.
     *
     * @return array<string,mixed>
     */
    private static function compute(RoomType $type, Carbon $date, array $ctx): array
    {
        $today = $ctx['today'];
        $mult = $ctx['mult'];
        $daysUntil = (int) $today->diffInDays($date, false);
        $isPast = $date->lt($today);

        $typeTotal = $ctx['type_total'];
        $occType = $typeTotal > 0 ? $ctx['type_booked'] / $typeTotal * 100 : 0.0;
        $occProperty = $ctx['property_total'] > 0 ? $ctx['property_booked'] / $ctx['property_total'] * 100 : 0.0;
        // Pooling: with 1-3 rooms per type occupancy jumps in huge steps, so
        // blend with the property level to smooth single-booking cliffs.
        $occ = round(0.5 * $occType + 0.5 * $occProperty, 1);

        $reference = RoomPricing::seasonPrice($type, $date);
        $override = $ctx['override'];
        $current = $override ? (float) $override->price : $reference;

        $demand = [];
        $eventFactors = [];
        $eventContext = $ctx['events']->map(fn ($event) => [
            'id' => $event->id,
            'name' => $event->name,
            'uplift_pct' => $event->uplift_pct !== null ? (float) $event->uplift_pct : null,
            'affects_price' => $event->uplift_pct !== null && (float) $event->uplift_pct != 0.0,
        ])->values()->all();

        if (! $isPast && $reference > 0 && $typeTotal > 0) {
            // 1. Occupancy — continuous curve anchored to the old bands at the
            // extremes (100% → +30) but smooth in between, scaled by strategy.
            $occPct = self::occupancyCurve($occ) * $mult;
            if (abs($occPct) >= 0.05) {
                $demand[] = [
                    'key' => 'occupancy',
                    'label' => sprintf(
                        'Sinjali i zënies %s%% (kategoria %d/%d = %s%%, prona %s%%)',
                        $occ,
                        $ctx['type_booked'],
                        $typeTotal,
                        round($occType, 1),
                        round($occProperty, 1),
                    ),
                    'pct' => round($occPct, 1),
                ];
            }

            // 2. Pickup pace — how fast this night filled since the baseline
            // snapshot. ALL-rooms count on both sides of the delta (the
            // snapshot's convention), so a room entering maintenance never
            // fakes a cancellation.
            if ($ctx['baseline_booked'] !== null && $ctx['baseline_span'] >= 3) {
                $pickup7 = ($ctx['property_booked_all'] - $ctx['baseline_booked']) * 7 / $ctx['baseline_span'];
                $pacePct = self::paceCurve($pickup7) * $mult;
                if (abs($pacePct) >= 0.05) {
                    $demand[] = ['key' => 'pace', 'label' => sprintf('Ritmi: %+.0f rezervime/javë', $pickup7), 'pct' => round($pacePct, 1)];
                }
            }

            // 3. Lead time — smooth last-minute taper when soft, far-out hold when hot.
            $leadPct = self::leadTimeCurve($daysUntil, $occ) * $mult;
            if (abs($leadPct) >= 0.05) {
                $demand[] = ['key' => 'lead_time', 'label' => $leadPct < 0
                    ? sprintf('%d ditë para dhe bosh — mos e humb natën', max($daysUntil, 0))
                    : 'Larg dhe kërkesë e lartë — mbaje çmimin', 'pct' => round($leadPct, 1)];
            }

            // 4. Day of week — Fri/Sat nights carry a premium.
            if (in_array((int) $date->dayOfWeekIso, [5, 6], true)) {
                $demand[] = ['key' => 'dow', 'label' => 'Fundjavë (Pre/Sht)', 'pct' => round(8.0 * $mult, 1)];
            }

            // 5. Events — the owner's explicit uplifts (either sign): deliberate
            // intent, so NEVER scaled by strategy and NEVER anti-nag-suppressed.
            foreach ($ctx['events'] as $event) {
                if ($event->uplift_pct !== null && (float) $event->uplift_pct != 0.0) {
                    $eventFactors[] = ['key' => 'event', 'label' => $event->name, 'pct' => round((float) $event->uplift_pct, 1)];
                }
            }
        }

        $product = fn (array $fs) => array_reduce($fs, fn ($p, $f) => $p * (1 + $f['pct'] / 100), 1.0);

        // Far-future anti-nag applies ONLY to the demand side: when the net
        // demand signal is a discount beyond the horizon, drop the demand
        // factors entirely (from the price AND the breakdown, so "Pse ky
        // çmim?" always multiplies out to the number). Events survive.
        $demandCollapsed = false;
        if ($daysUntil > self::DISCOUNT_HORIZON_DAYS && $product($demand) < 1) {
            $demand = [];
            $demandCollapsed = true;
        }

        $factors = array_merge($demand, $eventFactors);
        $suggested = round($reference * $product($factors), 2);

        // Owner guardrails via the normalized pair (inverted min>max = unset,
        // matching the apply guard); breakdown marks the clamp.
        [$min, $max] = $type->priceBounds();
        $clamped = null;
        if ($max !== null && $suggested > $max) {
            $suggested = round($max, 2);
            $clamped = 'max';
        }
        if ($min !== null && $suggested < $min) {
            $suggested = round($min, 2);
            $clamped = 'min';
        }

        $pctTotal = $reference > 0 ? round(($suggested / $reference - 1) * 100, 1) : 0.0;
        // A suggestion must MOVE the price meaningfully vs what's live today —
        // a +€0.09 nudge (the continuous curve grazing a knee) is noise, not
        // revenue advice. 1% floor; the autopilot keeps its own ≥5% gate.
        $moveVsCurrent = $current > 0 ? abs($suggested / $current - 1) * 100 : 0.0;
        $actionable = ! $isPast && $reference > 0 && $pctTotal != 0.0 && $moveVsCurrent >= 1.0;

        // WHY is this day quiet? Silence without a reason reads as a bug to
        // the owner — say it plainly (shown in the day panel).
        $quietReason = null;
        if (! $actionable && ! $isPast) {
            $quietReason = match (true) {
                $reference <= 0 || $typeTotal === 0 => 'Ky tip s\'ka çmim bazë ose dhoma aktive.',
                $demandCollapsed => sprintf('E largët (%d ditë) dhe ende e qetë — ulja shfaqet vetëm kur t\'i afrohet %d ditëve, që të mos shesësh lirë pa nevojë.', max($daysUntil, 0), self::DISCOUNT_HORIZON_DAYS),
                $factors === [] => sprintf('Sinjali i zënies (%s%%) është në zonën e mirë — çmimi është aty ku duhet.', $occ),
                default => 'Ndryshimi i llogaritur është shumë i vogël (nën 1%) për t\'ia vlejtur.',
            };
        }

        return [
            'date' => $date->toDateString(),
            'occupancy_pct' => (int) round($occ),
            'occupancy_type_pct' => (int) round($occType),
            'occupancy_property_pct' => (int) round($occProperty),
            'booked' => $ctx['type_booked'],
            'total' => $typeTotal,
            'reference' => round($reference, 2),
            'current_price' => round($current, 2),
            'suggested_price' => $actionable ? $suggested : round($current, 2),
            'adjustment_pct' => $actionable ? $pctTotal : 0.0,
            'factors' => $factors,
            'events' => $eventContext,
            'clamped' => $clamped,
            'kind' => $actionable ? ($pctTotal >= 20 ? 'peak' : ($pctTotal > 0 ? 'high' : 'low')) : null,
            'has_override' => (bool) $override,
            'days_until' => $daysUntil,
            'actionable' => $actionable,
            'quiet_reason' => $quietReason,
            'is_past' => $isPast,
        ];
    }

    /** Continuous occupancy curve: 0%→-15 … 40-70%→0 … 100%→+30 (pre-strategy). */
    private static function occupancyCurve(float $occ): float
    {
        return match (true) {
            $occ <= 40 => -15.0 * (40 - $occ) / 40,
            $occ < 70 => 0.0,
            $occ < 90 => 15.0 * ($occ - 70) / 20,
            default => 15.0 + 15.0 * (min($occ, 100) - 90) / 10,
        };
    }

    /** Pickup pace (bookings gained per 7 days, property-level) → pct. */
    private static function paceCurve(float $pickup7): float
    {
        return match (true) {
            $pickup7 >= 3 => 10.0,
            $pickup7 >= 1 => 5.0,
            $pickup7 <= -2 => -5.0, // net cancellations — demand cooling
            default => 0.0,
        };
    }

    /** Lead-time curve: smooth last-minute taper when soft; far-out hold when hot. */
    private static function leadTimeCurve(int $daysUntil, float $occ): float
    {
        if ($occ < 40 && $daysUntil >= 0) {
            return match (true) {
                $daysUntil <= 3 => -12.0,
                $daysUntil <= 7 => -8.0,
                $daysUntil <= self::DISCOUNT_HORIZON_DAYS => -4.0,
                default => 0.0,
            };
        }
        if ($occ >= 70 && $daysUntil >= 60) {
            return 5.0; // plenty of runway and already filling — hold firm
        }

        return 0.0;
    }

    /**
     * Property-level booked-per-stay-date as seen by the earliest snapshot
     * 3-7 days old. ['byStayDate' => [date => booked], 'spanDays' => int]
     */
    private static function paceBaseline(Carbon $today, Carbon $from, Carbon $to): array
    {
        $snapshotDate = RoomInventorySnapshot::whereDate('snapshot_date', '>=', $today->copy()->subDays(7))
            ->whereDate('snapshot_date', '<=', $today->copy()->subDays(3))
            ->min('snapshot_date');

        if (! $snapshotDate) {
            return ['byStayDate' => [], 'spanDays' => 0];
        }
        // min() returns the raw column value (may carry a 00:00:00 time) —
        // normalize to Y-m-d so the whereDate below actually matches.
        $snapshotDate = Carbon::parse($snapshotDate)->toDateString();

        $rows = RoomInventorySnapshot::whereDate('snapshot_date', $snapshotDate)
            ->whereDate('stay_date', '>=', $from->toDateString())
            ->whereDate('stay_date', '<=', $to->toDateString())
            ->get(['stay_date', 'booked'])
            ->groupBy(fn ($r) => $r->stay_date->toDateString())
            ->map(fn (Collection $g) => (int) $g->sum('booked'));

        return [
            'byStayDate' => $rows->all(),
            'spanDays' => (int) Carbon::parse($snapshotDate)->diffInDays($today),
        ];
    }
}
