<?php

namespace App\Services\Reporting;

use App\Models\Reservation;

final class ChannelPerformanceService
{
    public function __construct(
        private readonly StayRevenueAllocator $revenueAllocator,
        private readonly RoomRevenueService $roomRevenue,
        private readonly KpiCalculator $kpiCalculator,
    ) {}

    /** @return array{period:array,totals:array,rows:array,daily:array} */
    public function summary(ReportingPeriod $period): array
    {
        $reservations = Reservation::query()
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'channel', 'check_in_date', 'check_out_date', 'total_amount', 'commission_amount']);
        $discountFactors = $this->roomRevenue->discountFactors($reservations->pluck('id')->all());

        $daily = [];
        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $daily[$date->toDateString()] = [
                'direct_gross' => 0.0,
                'direct_net' => 0.0,
                'ota_gross' => 0.0,
                'ota_net' => 0.0,
            ];
        }

        $rows = $reservations
            ->groupBy(fn (Reservation $reservation) => Reservation::normalizeChannel($reservation->channel))
            ->map(function ($channelReservations, string $channel) use ($period, $discountFactors, &$daily) {
                $gross = 0.0;
                $commission = 0.0;
                $nights = 0;
                $isDirect = $channel === 'direct';

                foreach ($channelReservations as $reservation) {
                    $recognizedRoomRevenue = round(
                        (float) $reservation->total_amount * ($discountFactors[$reservation->id] ?? 1),
                        2,
                    );
                    $revenueByDate = $this->revenueAllocator->allocate(
                        $reservation->check_in_date,
                        $reservation->check_out_date,
                        $recognizedRoomRevenue,
                        $period,
                    );
                    $commissionByDate = $this->revenueAllocator->allocate(
                        $reservation->check_in_date,
                        $reservation->check_out_date,
                        $reservation->commission_amount ?? 0,
                        $period,
                    );
                    $gross += array_sum($revenueByDate);
                    $commission += array_sum($commissionByDate);
                    $nights += count($revenueByDate);

                    foreach ($revenueByDate as $date => $revenue) {
                        $daily[$date][$isDirect ? 'direct_gross' : 'ota_gross'] += $revenue;
                        $daily[$date][$isDirect ? 'direct_net' : 'ota_net'] += $revenue - ($commissionByDate[$date] ?? 0);
                    }
                }

                $gross = round($gross, 2);
                $commission = round($commission, 2);
                $net = round($gross - $commission, 2);

                return [
                    'channel' => $channel,
                    'kind' => $isDirect ? 'direct' : 'ota',
                    'bookings' => $channelReservations->count(),
                    'nights' => $nights,
                    'gross_revenue' => $gross,
                    'commission' => $commission,
                    'commission_rate' => $gross > 0 ? round($commission / $gross * 100, 1) : 0,
                    'net_revenue' => $net,
                    'adr' => $nights > 0 ? round($gross / $nights, 2) : 0,
                    'net_adr' => $nights > 0 ? round($net / $nights, 2) : 0,
                ];
            })
            ->sortByDesc('net_revenue')
            ->values();

        $gross = round((float) $rows->sum('gross_revenue'), 2);
        $commission = round((float) $rows->sum('commission'), 2);
        $net = round((float) $rows->sum('net_revenue'), 2);
        $nights = (int) $rows->sum('nights');
        $directRevenue = round((float) $rows->where('kind', 'direct')->sum('gross_revenue'), 2);
        $otaRevenue = round($gross - $directRevenue, 2);

        $rows = $rows->map(fn (array $row) => [
            ...$row,
            'revenue_share' => $gross > 0 ? round($row['gross_revenue'] / $gross * 100, 1) : 0,
        ])->all();

        return [
            'period' => $period->toArray(),
            'totals' => [
                'bookings' => (int) collect($rows)->sum('bookings'),
                'nights' => $nights,
                'gross_revenue' => $gross,
                'commission' => $commission,
                'commission_rate' => $gross > 0 ? round($commission / $gross * 100, 1) : 0,
                'net_revenue' => $net,
                'adr' => $nights > 0 ? round($gross / $nights, 2) : 0,
                'net_adr' => $nights > 0 ? round($net / $nights, 2) : 0,
                'direct_revenue' => $directRevenue,
                'ota_revenue' => $otaRevenue,
                'direct_share' => $gross > 0 ? round($directRevenue / $gross * 100, 1) : 0,
            ],
            'rows' => $rows,
            'daily' => collect($daily)->map(fn (array $day) => collect($day)
                ->map(fn ($value) => round((float) $value, 2))->all())->all(),
        ];
    }

    /** @return array{current:array,previous_period:array,changes:array} */
    public function withComparisons(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previous = $this->summary($period->previousPeriod());
        $changes = [];

        foreach (['gross_revenue', 'net_revenue', 'commission'] as $key) {
            $changes[$key] = $this->kpiCalculator->change(
                (float) $current['totals'][$key],
                (float) $previous['totals'][$key],
            );
        }
        $changes['direct_share'] = $previous['totals']['gross_revenue'] > 0
            ? round(
                (float) $current['totals']['direct_share'] - (float) $previous['totals']['direct_share'],
                1,
            )
            : null;

        return [
            'current' => $current,
            'previous_period' => $previous,
            'changes' => $changes,
        ];
    }
}
