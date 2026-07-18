<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

final class PickupPaceService
{
    public const HORIZONS = [1, 3, 7, 14, 30];

    public function __construct(private readonly StayRevenueAllocator $revenueAllocator) {}

    /** @return array{period:array,current:array,horizons:array,daily:array,baseline_days:?int,history_started_at:?string} */
    public function summary(ReportingPeriod $period, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();
        $current = $this->currentOnBooks($period);
        $typeCount = RoomType::query()->count();
        $horizons = [];
        $references = [];

        foreach (self::HORIZONS as $days) {
            $target = $asOf->subDays($days);
            $snapshotDate = RoomInventorySnapshot::query()
                ->whereDate('snapshot_date', '<=', $target->toDateString())
                ->whereDate('snapshot_date', '>=', $target->subDays(2)->toDateString())
                ->whereDate('stay_date', '>=', $period->from->toDateString())
                ->whereDate('stay_date', '<=', $period->to->toDateString())
                ->max('snapshot_date');

            $snapshotDay = $snapshotDate ? CarbonImmutable::parse($snapshotDate)->toDateString() : null;
            $reference = $snapshotDay
                ? $this->snapshotOnBooks($snapshotDay, $period, $typeCount)
                : null;
            $available = $reference !== null && $reference['complete'];
            $revenueAvailable = $available && $reference['revenue_complete'];

            $horizons[] = [
                'days' => $days,
                'snapshot_date' => $snapshotDay,
                'actual_days' => $snapshotDate ? CarbonImmutable::parse($snapshotDate)->diffInDays($asOf) : null,
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

        $baselineDays = collect([7, 3, 14, 1, 30])->first(fn (int $days) => isset($references[$days]));
        $baseline = $baselineDays ? $references[$baselineDays] : null;
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
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'total_amount']);

        foreach ($reservations as $reservation) {
            foreach ($this->revenueAllocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                $reservation->total_amount,
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

    private function snapshotOnBooks(string $snapshotDate, ReportingPeriod $period, int $typeCount): array
    {
        $rows = RoomInventorySnapshot::query()
            ->whereDate('snapshot_date', $snapshotDate)
            ->whereDate('stay_date', '>=', $period->from->toDateString())
            ->whereDate('stay_date', '<=', $period->to->toDateString())
            ->get(['stay_date', 'booked', 'booked_revenue']);
        $expectedRows = $period->days() * $typeCount;
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
