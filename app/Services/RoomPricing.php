<?php

namespace App\Services;

use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use Carbon\Carbon;

class RoomPricing
{
    /**
     * Price a stay night-by-night, applying the highest-priority season that
     * covers each night and has a rate for this room type; otherwise the
     * room type's base_price. Correctly prices stays that cross seasons.
     *
     * @return array{nights:int,total:float,base:float,breakdown:array<int,array{date:string,price:float}>}
     */
    public static function quote(RoomType $roomType, string|Carbon $checkIn, string|Carbon $checkOut): array
    {
        $start = $checkIn instanceof Carbon ? $checkIn->copy() : Carbon::parse($checkIn);
        $end = $checkOut instanceof Carbon ? $checkOut->copy() : Carbon::parse($checkOut);
        $base = (float) $roomType->base_price;

        // Guard: a non-positive range (check-out on/before check-in) is not a stay.
        // Return a clearly-zero quote instead of silently pricing money to 0 inside the loop.
        if ($end->lte($start)) {
            return ['nights' => 0, 'total' => 0.0, 'base' => $base, 'breakdown' => []];
        }

        // ALL seasons, highest priority first (ties broken by id). We KEEP seasons that
        // have no rate for this type: the highest-priority season COVERING a night governs
        // that night, and if it carries no per-type rate the night falls back to base_price
        // — i.e. an empty matrix cell means "use the base price", exactly as the Cmimet UI
        // promises ("Bosh = perdoret cmimi bazё"). (Do NOT pre-filter by rate, or a
        // lower-priority overlapping season would silently win the night.)
        $seasons = Season::query()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->with(['rates' => fn ($q) => $q->where('room_type_id', $roomType->id)])
            ->get()
            ->map(fn ($s) => [
                'start' => $s->start_date,
                'end' => $s->end_date,
                'rate' => optional($s->rates->first())->price, // null when no cell is set for this type
            ])
            ->values();

        $total = 0.0;
        $breakdown = [];

        for ($d = $start->copy(); $d->lt($end); $d->addDay()) {
            $price = $base;
            foreach ($seasons as $s) { // already ordered by priority desc
                if ($d->betweenIncluded($s['start'], $s['end'])) {
                    // First (highest-priority) covering season wins; no rate → base price.
                    $price = $s['rate'] !== null ? (float) $s['rate'] : $base;
                    break;
                }
            }
            $total += $price;
            $breakdown[] = ['date' => $d->toDateString(), 'price' => round($price, 2)];
        }

        return [
            'nights' => (int) $start->diffInDays($end),
            'total' => round($total, 2),
            'base' => $base,
            'breakdown' => $breakdown,
        ];
    }

    public static function total(RoomType $roomType, string|Carbon $checkIn, string|Carbon $checkOut): float
    {
        return self::quote($roomType, $checkIn, $checkOut)['total'];
    }

    /**
     * Lowest price a guest could pay for this type (base + any season rate) —
     * used for the public "Nga €X".
     */
    public static function fromPrice(RoomType $roomType): float
    {
        $prices = SeasonRate::where('room_type_id', $roomType->id)
            ->pluck('price')
            ->map(fn ($p) => (float) $p)
            ->all();
        $prices[] = (float) $roomType->base_price;

        // Only positive prices count toward the public "Nga €X" — a stray 0 (an unset/
        // zeroed base price or a typo rate) must never advertise a room as free.
        $prices = array_filter($prices, fn ($p) => $p > 0);

        return $prices ? round(min($prices), 2) : 0.0;
    }
}
