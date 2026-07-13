<?php

namespace App\Services;

use App\Models\CompRate;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Rate shopping (Phase 1 — display only): fetches competitor nightly prices
 * for the owner-configured comp-set and stores them in comp_rates. Smart
 * Pricing shows the market summary next to the engine's suggestion; nothing
 * here influences computed prices (that is Phase 2, behind its own decision).
 *
 * Source: SerpAPI Google Hotels — ONE area search per stay date returns every
 * property with its nightly price, so a 30-day horizon costs ~30 requests per
 * snapshot regardless of how many competitors are tracked. Competitors are
 * matched by normalized name containment against the owner's list.
 *
 * Everything is owner-controlled from Settings ("Çmimet e Tregut"): the
 * enabled toggle (OFF = zero API calls, zero cost), the API key, the
 * competitor list, the fetch frequency, and the search area.
 */
class MarketRates
{
    public const DEFAULT_COMPETITORS = [
        'Hotel Piccolino',
        'Mano',
        'Vila Xika',
        'Vila Duka',
        'Villa Margarit',
        'Villa Hysa',
        'Santa Oliva Suites',
        'Villa Green Garden',
        'Monas Guesthouse',
    ];

    public const DEFAULT_SEARCH_QUERY = 'Hotels Sarande Albania';

    public const DEFAULT_HORIZON_DAYS = 30;

    public static function enabled(): bool
    {
        return (bool) Setting::get('market_rates.enabled', false) && self::apiKey() !== '';
    }

    public static function apiKey(): string
    {
        return trim((string) Setting::get('market_rates.api_key', ''));
    }

    /** @return array<int,string> */
    public static function competitors(): array
    {
        $list = Setting::get('market_rates.competitors', null);

        $list = is_array($list) ? array_values(array_filter(array_map(
            fn ($c) => trim((string) $c),
            $list,
        ))) : [];

        return $list !== [] ? $list : self::DEFAULT_COMPETITORS;
    }

    public static function frequency(): string
    {
        $freq = (string) Setting::get('market_rates.frequency', '3x_week');

        return in_array($freq, ['daily', '3x_week'], true) ? $freq : '3x_week';
    }

    public static function searchQuery(): string
    {
        return trim((string) Setting::get('market_rates.search_query', '')) ?: self::DEFAULT_SEARCH_QUERY;
    }

    /**
     * Whether a SCHEDULED run should fetch today. Manual runs always may.
     * 3x_week = Mon/Wed/Fri — enough to see movement at a third of the cost.
     */
    public static function shouldRunToday(): bool
    {
        return self::frequency() === 'daily'
            || in_array(CarbonImmutable::today()->dayOfWeekIso, [1, 3, 5], true);
    }

    /**
     * Fetch one snapshot: for each stay date in [today, today+days), one area
     * search; store every competitor matched in the response. Returns a
     * summary for the command output. Failures on single dates are counted,
     * not fatal — a partial snapshot is still useful and the next run heals it.
     *
     * @return array{dates:int,rows:int,failed:int,matched:array<string,int>}
     */
    public function fetchSnapshot(int $days = self::DEFAULT_HORIZON_DAYS): array
    {
        $competitors = self::competitors();
        $snapshot = CarbonImmutable::today();
        $summary = ['dates' => 0, 'rows' => 0, 'failed' => 0, 'matched' => []];

        for ($i = 0; $i < $days; $i++) {
            $date = $snapshot->addDays($i);
            $properties = $this->searchDate($date);
            if ($properties === null) {
                $summary['failed']++;

                continue;
            }
            $summary['dates']++;

            foreach ($properties as $property) {
                $name = trim((string) ($property['name'] ?? ''));
                $price = $this->nightlyPrice($property);
                $competitor = $name !== '' && $price !== null ? $this->matchCompetitor($name, $competitors) : null;
                if ($competitor === null) {
                    continue;
                }

                CompRate::updateOrCreate(
                    [
                        'competitor' => $competitor,
                        'date' => $date->toDateString(),
                        'snapshot_date' => $snapshot->toDateString(),
                    ],
                    ['price' => $price, 'currency' => 'EUR', 'source' => 'google_hotels'],
                );
                $summary['rows']++;
                $summary['matched'][$competitor] = ($summary['matched'][$competitor] ?? 0) + 1;
            }
        }

        return $summary;
    }

    /**
     * Market summary per date over [from, to] from the LATEST snapshot of each
     * date: median / min / max / how many competitors had a price. Display
     * data for Smart Pricing — deliberately not part of the engine (Phase 1).
     *
     * @return array<string,array{median:float,min:float,max:float,count:int}>
     */
    public static function summaryForRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = CompRate::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('snapshot_date')
            ->get(['competitor', 'date', 'price', 'snapshot_date']);
        if ($rows->isEmpty()) {
            return [];
        }

        return $rows
            ->groupBy(fn ($r) => $r->date->toDateString())
            ->map(function ($ofDate) {
                $latest = $ofDate->where('snapshot_date', $ofDate->max('snapshot_date'));
                $prices = $latest->pluck('price')->map(fn ($p) => (float) $p)->sort()->values();
                $n = $prices->count();
                $median = $n % 2 === 1
                    ? $prices[intdiv($n, 2)]
                    : round(($prices[$n / 2 - 1] + $prices[$n / 2]) / 2, 2);

                return [
                    'median' => $median,
                    'min' => (float) $prices->first(),
                    'max' => (float) $prices->last(),
                    'count' => $n,
                ];
            })
            ->all();
    }

    /**
     * One Google Hotels area search for one stay night (date .. date+1).
     * Returns the raw properties array, or null on a failed request.
     */
    protected function searchDate(CarbonImmutable $date): ?array
    {
        $resp = Http::timeout(30)->retry(2, 500, throw: false)->get('https://serpapi.com/search.json', [
            'engine' => 'google_hotels',
            'q' => self::searchQuery(),
            'check_in_date' => $date->toDateString(),
            'check_out_date' => $date->addDay()->toDateString(),
            'adults' => 2,
            'currency' => 'EUR',
            'api_key' => self::apiKey(),
        ]);

        if (! $resp->successful()) {
            return null;
        }

        return (array) $resp->json('properties', []);
    }

    /** The owner's competitor entry this property name matches, or null. */
    protected function matchCompetitor(string $propertyName, array $competitors): ?string
    {
        $name = Str::lower($propertyName);
        foreach ($competitors as $competitor) {
            $c = Str::lower(trim($competitor));
            if ($c !== '' && (str_contains($name, $c) || str_contains($c, $name))) {
                return $competitor;
            }
        }

        return null;
    }

    /** Nightly price from a Google Hotels property row, or null when absent. */
    protected function nightlyPrice(array $property): ?float
    {
        $extracted = $property['rate_per_night']['extracted_lowest'] ?? null;
        if (is_numeric($extracted) && $extracted > 0) {
            return round((float) $extracted, 2);
        }

        // Fallback: "€88" / "88 €" string form.
        $lowest = (string) ($property['rate_per_night']['lowest'] ?? '');
        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '.', $lowest));

        return is_numeric($digits) && (float) $digits > 0 ? round((float) $digits, 2) : null;
    }
}
