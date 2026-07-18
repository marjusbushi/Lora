<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

final class CancellationRiskService
{
    public function __construct(private readonly KpiCalculator $kpiCalculator) {}

    /** @return array{period:array,summary:array,daily:array,channels:array,losses:array,at_risk:array} */
    public function summary(ReportingPeriod $period, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();
        $reservations = Reservation::query()
            ->whereDate('check_in_date', '>=', $period->from->toDateString())
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->with(['room:id,room_number', 'guest:id,first_name,last_name'])
            ->get([
                'id', 'room_id', 'guest_id', 'channel', 'status', 'check_in_date',
                'check_out_date', 'total_amount', 'no_show_at',
            ]);

        $records = $reservations->map(function (Reservation $reservation) use ($asOf) {
            $isNoShow = $reservation->no_show_at !== null;
            $isCancelled = $reservation->status === 'cancelled' && ! $isNoShow;
            $isAtRisk = ! $isCancelled
                && ! $isNoShow
                && in_array($reservation->status, ['pending', 'confirmed'], true)
                && $reservation->check_in_date->lt($asOf);

            return [
                'id' => $reservation->id,
                'guest' => trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: 'Mysafir',
                'room' => $reservation->room?->room_number,
                'channel' => Reservation::normalizeChannel($reservation->channel),
                'check_in' => $reservation->check_in_date->toDateString(),
                'check_out' => $reservation->check_out_date->toDateString(),
                'value' => round((float) $reservation->total_amount, 2),
                'is_cancelled' => $isCancelled,
                'is_no_show' => $isNoShow,
                'is_at_risk' => $isAtRisk,
            ];
        });

        $total = $records->count();
        $cancelled = $records->where('is_cancelled', true);
        $noShows = $records->where('is_no_show', true);
        $atRisk = $records->where('is_at_risk', true);
        $cancelledValue = round((float) $cancelled->sum('value'), 2);
        $noShowValue = round((float) $noShows->sum('value'), 2);

        $daily = [];
        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $daily[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'cancelled_count' => 0,
                'no_show_count' => 0,
                'cancelled_value' => 0.0,
                'no_show_value' => 0.0,
            ];
        }
        foreach ($cancelled as $record) {
            $daily[$record['check_in']]['cancelled_count']++;
            $daily[$record['check_in']]['cancelled_value'] += $record['value'];
        }
        foreach ($noShows as $record) {
            $daily[$record['check_in']]['no_show_count']++;
            $daily[$record['check_in']]['no_show_value'] += $record['value'];
        }
        $daily = collect($daily)->map(fn (array $day) => [
            ...$day,
            'cancelled_value' => round($day['cancelled_value'], 2),
            'no_show_value' => round($day['no_show_value'], 2),
        ])->values()->all();

        $channels = $records->groupBy('channel')
            ->map(function ($channelRecords, string $channel) {
                $count = $channelRecords->count();
                $channelCancelled = $channelRecords->where('is_cancelled', true);
                $channelNoShows = $channelRecords->where('is_no_show', true);

                return [
                    'channel' => $channel,
                    'bookings' => $count,
                    'cancelled' => $channelCancelled->count(),
                    'cancellation_rate' => $count > 0 ? round($channelCancelled->count() / $count * 100, 1) : 0,
                    'no_shows' => $channelNoShows->count(),
                    'no_show_rate' => $count > 0 ? round($channelNoShows->count() / $count * 100, 1) : 0,
                    'at_risk' => $channelRecords->where('is_at_risk', true)->count(),
                    'lost_value' => round((float) $channelCancelled->sum('value') + (float) $channelNoShows->sum('value'), 2),
                ];
            })
            ->sortByDesc('lost_value')
            ->values()
            ->all();

        return [
            'period' => $period->toArray(),
            'summary' => [
                'total_count' => $total,
                'cancelled_count' => $cancelled->count(),
                'cancellation_rate' => $total > 0 ? round($cancelled->count() / $total * 100, 1) : 0,
                'cancelled_value' => $cancelledValue,
                'no_show_count' => $noShows->count(),
                'no_show_rate' => $total > 0 ? round($noShows->count() / $total * 100, 1) : 0,
                'no_show_value' => $noShowValue,
                'lost_value' => round($cancelledValue + $noShowValue, 2),
                'at_risk_count' => $atRisk->count(),
                'at_risk_value' => round((float) $atRisk->sum('value'), 2),
            ],
            'daily' => $daily,
            'channels' => $channels,
            'losses' => $cancelled->map(fn (array $row) => [...$row, 'type' => 'cancelled'])
                ->concat($noShows->map(fn (array $row) => [...$row, 'type' => 'no_show']))
                ->sortByDesc('check_in')
                ->values()
                ->all(),
            'at_risk' => $atRisk->sortBy('check_in')->values()->all(),
        ];
    }

    /** @return array{current:array,previous_period:array,changes:array} */
    public function withComparisons(ReportingPeriod $period, ?CarbonImmutable $asOf = null): array
    {
        $current = $this->summary($period, $asOf);
        $previous = $this->summary($period->previousPeriod(), $asOf);
        $hasPrevious = $previous['summary']['total_count'] > 0;

        return [
            'current' => $current,
            'previous_period' => $previous,
            'changes' => [
                'cancellation_rate' => $hasPrevious
                    ? round($current['summary']['cancellation_rate'] - $previous['summary']['cancellation_rate'], 1)
                    : null,
                'lost_value' => $this->kpiCalculator->change(
                    (float) $current['summary']['lost_value'],
                    (float) $previous['summary']['lost_value'],
                ),
                'no_show_rate' => $hasPrevious
                    ? round($current['summary']['no_show_rate'] - $previous['summary']['no_show_rate'], 1)
                    : null,
                'at_risk_count' => $this->kpiCalculator->change(
                    (float) $current['summary']['at_risk_count'],
                    (float) $previous['summary']['at_risk_count'],
                ),
            ],
        ];
    }
}
