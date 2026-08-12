<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

final class PickupPaceService
{
    public const HORIZONS = [1, 3, 7, 14, 30];

    public function __construct(
        private readonly StayRevenueAllocator $revenueAllocator,
        private readonly RoomRevenueService $roomRevenue,
    ) {}

    /** @return array{period:array,current:array,horizons:array,daily:array,expected:?array,baseline_days:?int,history_started_at:?string} */
    public function summary(ReportingPeriod $period, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();
        $current = $this->currentOnBooks($period);
        $typeIds = RoomType::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $horizons = [];
        $references = [];

        foreach (self::HORIZONS as $days) {
            $target = $asOf->subDays($days);
            $reference = $this->snapshotNear($target, $period, $typeIds);
            $snapshotDay = $reference['snapshot_date'] ?? null;
            $available = $reference !== null && $reference['complete'];
            $revenueAvailable = $available && $reference['revenue_complete'];

            $horizons[] = [
                'days' => $days,
                'snapshot_date' => $snapshotDay,
                'actual_days' => $snapshotDay ? (int) CarbonImmutable::parse($snapshotDay)->diffInDays($asOf) : null,
                'available' => $available,
                'revenue_available' => $revenueAvailable,
                'current_nights' => $current['nights'],
                'reference_nights' => $available ? $reference['nights'] : null,
                'pickup_nights' => $available ? $current['nights'] - $reference['nights'] : null,
                'current_revenue' => $current['revenue'],
                'reference_revenue' => $revenueAvailable ? $reference['revenue'] : null,
                'pickup_revenue' => $revenueAvailable ? round($current['revenue'] - $reference['revenue'], 2) : null,
                'coverage' => $reference['coverage'] ?? 0,
            ];

            if ($available) {
                $references[$days] = $reference;
            }
        }

        $materialization = $this->materialization($asOf, $typeIds);
        foreach ($horizons as $index => $horizon) {
            $learned = $materialization[$horizon['days']] ?? null;
            $horizons[$index]['materialization_pct'] = $learned['pct'] ?? null;
            $horizons[$index]['materialization_sample'] = $learned['sample'] ?? 0;
            $horizons[$index]['expected_nights'] = $learned
                ? (int) round($current['nights'] * $learned['pct'] / 100)
                : null;
        }

        $baselineDays = collect([7, 3, 14, 1, 30])->first(fn (int $days) => isset($references[$days]));
        $baseline = $baselineDays ? $references[$baselineDays] : null;
        $baselineMaterialization = $baselineDays ? ($materialization[$baselineDays] ?? null) : null;
        $daily = collect($current['daily'])->map(function (array $day, string $date) use ($baseline) {
            $reference = $baseline['daily'][$date] ?? null;

            return [
                'date' => $date,
                'current_nights' => $day['nights'],
                'reference_nights' => $reference['nights'] ?? null,
                'pickup_nights' => $reference ? $day['nights'] - $reference['nights'] : null,
                'current_revenue' => $day['revenue'],
                'reference_revenue' => ($baseline['revenue_complete'] ?? false) ? ($reference['revenue'] ?? 0) : null,
            ];
        })->values()->all();

        return [
            'period' => $period->toArray(),
            'current' => ['nights' => $current['nights'], 'revenue' => $current['revenue']],
            'horizons' => $horizons,
            'daily' => $daily,
            // "How much of the book really arrives" applied to today's book,
            // learned at the same horizon the daily chart uses as baseline.
            'expected' => $baselineMaterialization ? [
                'days' => $baselineDays,
                'pct' => $baselineMaterialization['pct'],
                'sample' => $baselineMaterialization['sample'],
                'nights' => (int) round($current['nights'] * $baselineMaterialization['pct'] / 100),
            ] : null,
            'baseline_days' => $baselineDays,
            'history_started_at' => ($historyStart = RoomInventorySnapshot::query()->min('snapshot_date'))
                ? CarbonImmutable::parse($historyStart)->toDateString()
                : null,
        ];
    }

    private function currentOnBooks(ReportingPeriod $period): array
    {
        $daily = [];
        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $daily[$date->toDateString()] = ['rooms' => [], 'nights' => 0, 'revenue' => 0.0];
        }

        $reservations = Reservation::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'pending'])
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'total_amount_base']);
        $discountFactors = $this->roomRevenue->discountFactors($reservations->pluck('id')->all());

        foreach ($reservations as $reservation) {
            $recognizedRoomRevenue = round(
                (float) $reservation->total_amount_base * ($discountFactors[$reservation->id] ?? 1),
                2,
            );
            foreach ($this->revenueAllocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                $recognizedRoomRevenue,
                $period,
            ) as $date => $amount) {
                $daily[$date]['rooms'][(string) $reservation->room_id] = true;
                $daily[$date]['revenue'] += $amount;
            }
        }

        $daily = collect($daily)->map(fn (array $day) => [
            'nights' => count($day['rooms']),
            'revenue' => round($day['revenue'], 2),
        ])->all();

        return [
            'nights' => (int) collect($daily)->sum('nights'),
            'revenue' => round((float) collect($daily)->sum('revenue'), 2),
            'daily' => $daily,
        ];
    }

    /**
     * The materialization ("wash") rate per horizon, learned from ELAPSED stay
     * dates: of the nights the photo showed N days before arrival, how many
     * actually happened. Pairs whose photo shows 0 booked carry no signal and
     * are skipped — this also keeps a migration gap (empty early photos) from
     * poisoning the percentage.
     *
     * @param  array<int>  $typeIds
     * @return array<int, array{pct: float, sample: int}>
     */
    private function materialization(CarbonImmutable $asOf, array $typeIds, int $learningDays = 30): array
    {
        if ($typeIds === []) {
            return [];
        }

        $learnFrom = $asOf->subDays($learningDays);
        $learnTo = $asOf->subDay();
        if ($learnTo->lessThan($learnFrom)) {
            return [];
        }

        $maxHorizon = max(self::HORIZONS);
        // One fetch, PHP-side pairing: portable across MySQL and SQLite and
        // tiny in volume (learning window × room types × horizon count).
        $photoNights = RoomInventorySnapshot::query()
            ->whereDate('stay_date', '>=', $learnFrom->toDateString())
            ->whereDate('stay_date', '<=', $learnTo->toDateString())
            ->whereDate('snapshot_date', '>=', $learnFrom->subDays($maxHorizon)->toDateString())
            ->whereIn('room_type_id', $typeIds)
            ->get(['snapshot_date', 'stay_date', 'booked'])
            ->groupBy(fn (RoomInventorySnapshot $row) => $row->stay_date->toDateString()
                .'|'.(int) $row->stay_date->toImmutable()->diffInDays($row->snapshot_date->toImmutable(), true))
            ->map(fn ($rows) => (int) $rows->sum('booked'));

        // What actually happened those nights: realized stays only.
        $actualByDate = [];
        $realized = Reservation::query()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $learnTo->toDateString())
            ->whereDate('check_out_date', '>', $learnFrom->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date']);
        foreach ($realized as $reservation) {
            for ($date = $reservation->check_in_date->toImmutable(); $date->lt($reservation->check_out_date); $date = $date->addDay()) {
                $key = $date->toDateString();
                if ($date->gte($learnFrom) && $date->lte($learnTo)) {
                    $actualByDate[$key][(string) $reservation->room_id] = true;
                }
            }
        }

        $learned = [];
        foreach (self::HORIZONS as $days) {
            $bookedSum = 0;
            $actualSum = 0;
            $sample = 0;
            for ($date = $learnFrom; $date->lessThanOrEqualTo($learnTo); $date = $date->addDay()) {
                $booked = $photoNights[$date->toDateString().'|'.$days] ?? 0;
                if ($booked <= 0) {
                    continue;
                }
                $bookedSum += $booked;
                $actualSum += count($actualByDate[$date->toDateString()] ?? []);
                $sample++;
            }

            if ($bookedSum > 0) {
                $learned[$days] = [
                    'pct' => round($actualSum / $bookedSum * 100, 1),
                    'sample' => $sample,
                ];
            }
        }

        return $learned;
    }

    /** @param array<int> $typeIds */
    private function snapshotNear(CarbonImmutable $target, ReportingPeriod $period, array $typeIds): ?array
    {
        if ($typeIds === []) {
            return null;
        }

        $snapshotDays = RoomInventorySnapshot::query()
            ->whereDate('snapshot_date', '<=', $target->toDateString())
            ->whereDate('snapshot_date', '>=', $target->subDays(2)->toDateString())
            ->whereDate('stay_date', '>=', $period->from->toDateString())
            ->whereDate('stay_date', '<=', $period->to->toDateString())
            ->orderByDesc('snapshot_date')
            ->pluck('snapshot_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->values();
        $latest = null;

        foreach ($snapshotDays as $snapshotDay) {
            $reference = $this->snapshotOnBooks($snapshotDay, $period, $typeIds);
            $reference['snapshot_date'] = $snapshotDay;
            $latest ??= $reference;
            if ($reference['complete']) {
                return $reference;
            }
        }

        return $latest;
    }

    /** @param array<int> $typeIds */
    private function snapshotOnBooks(string $snapshotDate, ReportingPeriod $period, array $typeIds): array
    {
        $rows = RoomInventorySnapshot::query()
            ->whereDate('snapshot_date', $snapshotDate)
            ->whereDate('stay_date', '>=', $period->from->toDateString())
            ->whereDate('stay_date', '<=', $period->to->toDateString())
            ->whereIn('room_type_id', $typeIds)
            ->get(['stay_date', 'booked', 'booked_revenue']);
        $expectedRows = $period->days() * count($typeIds);
        $coverage = $expectedRows > 0 ? min(100, round($rows->count() / $expectedRows * 100, 1)) : 0;
        $revenueComplete = $rows->isNotEmpty() && $rows->every(fn ($row) => $row->booked_revenue !== null);
        $daily = $rows->groupBy(fn ($row) => $row->stay_date->toDateString())
            ->map(fn ($dayRows) => [
                'nights' => (int) $dayRows->sum('booked'),
                'revenue' => $revenueComplete ? round((float) $dayRows->sum('booked_revenue'), 2) : null,
            ])->all();

        return [
            'complete' => $expectedRows > 0 && $rows->count() === $expectedRows,
            'revenue_complete' => $revenueComplete,
            'coverage' => $coverage,
            'nights' => (int) $rows->sum('booked'),
            'revenue' => $revenueComplete ? round((float) $rows->sum('booked_revenue'), 2) : null,
            'daily' => $daily,
        ];
    }
}
