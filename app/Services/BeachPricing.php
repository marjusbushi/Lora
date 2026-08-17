<?php

namespace App\Services;

use App\Models\BeachSeason;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Çmimi ditor i plazhit: sezoni që mbulon datën (kur ka çmim për atë zonë)
 * përndryshe çmimi bazë i zonës. Datat INKLUZIVE në të dy skajet — i njëjti
 * kuptim si beach_reservations (15–17 = 3 ditë). Sezonet s'mbivendosen
 * (validohet në SaveBeachSeasonRequest), ndaj një datë ka maksimumi një sezon.
 */
class BeachPricing
{
    /** Totali autoritativ i një çadre për intervalin [start, end] inkluziv. */
    public function totalFor(BeachUnit $unit, string $start, string $end): float
    {
        $unit->loadMissing('zone');

        $daily = $this->dailyPricesUsing($unit->zone, $start, $end, $this->seasonsTouching($start, $end));

        return round(array_sum($daily), 2);
    }

    /**
     * Për UI-në publike: per zonë — totali i intervalit + min/max ditor
     * (min≠max ⇒ intervali kap më shumë se një nivel çmimi).
     *
     * @param  Collection<int, BeachZone>  $zones
     * @return array<int, array{total: float, min_daily: float, max_daily: float}>
     */
    public function breakdown(Collection $zones, string $start, string $end): array
    {
        $seasons = $this->seasonsTouching($start, $end);

        return $zones->mapWithKeys(function (BeachZone $zone) use ($seasons, $start, $end) {
            $daily = $this->dailyPricesUsing($zone, $start, $end, $seasons);

            return [$zone->id => [
                'total' => round(array_sum($daily), 2),
                'min_daily' => round(min($daily), 2),
                'max_daily' => round(max($daily), 2),
            ]];
        })->all();
    }

    /**
     * @param  Collection<int, BeachSeason>  $seasons
     * @return array<string, float> datë (Y-m-d) => çmim
     */
    private function dailyPricesUsing(BeachZone $zone, string $start, string $end, Collection $seasons): array
    {
        $base = (float) $zone->price_per_day;
        $prices = [];
        $cursor = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($end);

        while ($cursor->lessThanOrEqualTo($last)) {
            $date = $cursor->toDateString();
            $season = $seasons->first(fn (BeachSeason $season) => $season->start_date->toDateString() <= $date
                && $season->end_date->toDateString() >= $date);
            $seasonPrice = $season?->prices->firstWhere('beach_zone_id', $zone->id)?->price_per_day;

            $prices[$date] = $seasonPrice !== null ? (float) $seasonPrice : $base;
            $cursor = $cursor->addDay();
        }

        return $prices;
    }

    /**
     * Një query e vetme: sezonet (me çmimet) që prekin intervalin — pa N+1 ditor.
     *
     * @return Collection<int, BeachSeason>
     */
    private function seasonsTouching(string $start, string $end): Collection
    {
        return BeachSeason::query()
            ->with('prices')
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->get();
    }
}
