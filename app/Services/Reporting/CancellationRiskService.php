<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

final class CancellationRiskService
{
    public function __construct(
        private readonly KpiCalculator $kpiCalculator,
        private readonly RoomRevenueService $roomRevenue,
    ) {}

    /** @return array{period:array,summary:array,daily:array,channels:array,losses:array,at_risk:array,risk_levels:array} */
    public function summary(ReportingPeriod $period, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();
        $channelRisk = $this->channelRisk($asOf);
        $reservations = Reservation::query()
            ->whereDate('check_in_date', '>=', $period->from->toDateString())
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->with([
                'room:id,room_number',
                'guest:id,first_name,last_name',
                'folioItems:id,reservation_id,pos_order_id,type,amount_base',
                'payments' => fn ($query) => $query->notVoided()->select('id', 'reservation_id', 'amount_base', 'type'),
            ])
            ->get([
                'id', 'room_id', 'guest_id', 'channel', 'status', 'check_in_date',
                'check_out_date', 'total_amount_base', 'no_show_at', 'created_at',
            ]);
        $discountFactors = $this->roomRevenue->discountFactors($reservations->pluck('id')->all());

        $records = $reservations->map(function (Reservation $reservation) use ($asOf, $channelRisk, $discountFactors) {
            $isNoShow = $reservation->no_show_at !== null;
            $isCancelled = $reservation->status === 'cancelled' && ! $isNoShow;
            $channel = Reservation::normalizeChannel($reservation->channel);
            $paid = round((float) $reservation->payments
                ->sum(fn ($payment) => match ($payment->type ?? 'payment') {
                    'payment', 'deposit' => (float) $payment->amount_base,
                    'refund' => -abs((float) $payment->amount_base),
                    default => 0.0,
                }), 2);
            $additionalCharges = (float) $reservation->folioItems
                ->whereNotIn('type', ['discount', 'room'])
                ->sum('amount_base');
            $discounts = (float) $reservation->folioItems
                ->where('type', 'discount')
                ->sum('amount_base');
            $billTotal = round(max(0, (float) $reservation->total_amount_base + $additionalCharges - $discounts), 2);
            $value = round(
                (float) $reservation->total_amount_base * ($discountFactors[$reservation->id] ?? 1),
                2,
            );
            $balance = round(max(0, $billTotal - $paid), 2);
            $risk = $this->riskFor($reservation, $asOf, $channelRisk[$channel] ?? 0.0, $balance, $billTotal);

            return [
                'id' => $reservation->id,
                'guest' => trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: 'Mysafir',
                'room' => $reservation->room?->room_number,
                'channel' => $channel,
                'check_in' => $reservation->check_in_date->toDateString(),
                'check_out' => $reservation->check_out_date->toDateString(),
                'value' => $value,
                'bill_total' => $billTotal,
                'paid' => $paid,
                'balance' => $balance,
                'is_cancelled' => $isCancelled,
                'is_no_show' => $isNoShow,
                'is_at_risk' => $risk['is_at_risk'],
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'risk_drivers' => $risk['drivers'],
                'recommended_action' => $risk['action'],
            ];
        });

        $total = $records->count();
        $cancelled = $records->where('is_cancelled', true);
        $noShows = $records->where('is_no_show', true);
        $atRisk = $records->where('is_at_risk', true);
        $riskLevels = collect(['critical', 'high', 'medium', 'low'])->mapWithKeys(fn (string $level) => [
            $level => $atRisk->where('risk_level', $level)->count(),
        ])->all();
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
                'at_risk_value' => round((float) $atRisk->sum('balance'), 2),
            ],
            'daily' => $daily,
            'channels' => $channels,
            'losses' => $cancelled->map(fn (array $row) => [...$row, 'type' => 'cancelled'])
                ->concat($noShows->map(fn (array $row) => [...$row, 'type' => 'no_show']))
                ->sortByDesc('check_in')
                ->values()
                ->all(),
            'at_risk' => $atRisk->sortBy([
                ['risk_score', 'desc'],
                ['check_in', 'asc'],
            ])->values()->all(),
            'risk_levels' => $riskLevels,
        ];
    }

    /** @return array<string,float> */
    private function channelRisk(CarbonImmutable $asOf): array
    {
        return Reservation::query()
            ->whereDate('check_in_date', '>=', $asOf->subDays(365)->toDateString())
            ->whereDate('check_in_date', '<', $asOf->toDateString())
            ->get(['channel', 'status', 'no_show_at'])
            ->groupBy(fn (Reservation $reservation) => Reservation::normalizeChannel($reservation->channel))
            ->map(function ($reservations): float {
                $incidents = $reservations->filter(fn (Reservation $reservation) => $reservation->status === 'cancelled' || $reservation->no_show_at !== null)->count();

                return $reservations->isEmpty() ? 0.0 : round($incidents / $reservations->count() * 100, 1);
            })
            ->all();
    }

    /** @return array{is_at_risk:bool,score:int,level:string,drivers:array,action:string} */
    private function riskFor(Reservation $reservation, CarbonImmutable $asOf, float $channelRate, float $balance, float $billTotal): array
    {
        if ($reservation->status === 'cancelled' || $reservation->no_show_at !== null || ! in_array($reservation->status, ['pending', 'confirmed'], true)) {
            return ['is_at_risk' => false, 'score' => 0, 'level' => 'low', 'drivers' => [], 'action' => 'none'];
        }

        $score = 0;
        $drivers = [];
        $overdue = $reservation->check_in_date->lt($asOf);

        if ($overdue) {
            $score = 100;
            $drivers[] = 'arrival_overdue';
        } else {
            if ($reservation->status === 'pending') {
                $score += 35;
                $drivers[] = 'pending_confirmation';
            }
            if ($balance >= $billTotal && $billTotal > 0) {
                $score += 25;
                $drivers[] = 'unpaid';
            } elseif ($balance > 0) {
                $score += 15;
                $drivers[] = 'partial_payment';
            }
            if ($channelRate >= 30) {
                $score += 25;
                $drivers[] = 'high_risk_channel';
            } elseif ($channelRate >= 15) {
                $score += 15;
                $drivers[] = 'elevated_channel_risk';
            }
            $leadDays = CarbonImmutable::parse($reservation->created_at)->startOfDay()->diffInDays($reservation->check_in_date, false);
            if ($leadDays >= 45) {
                $score += 10;
                $drivers[] = 'long_lead_time';
            }
            if ($balance > 0 && $reservation->check_in_date->lessThanOrEqualTo($asOf->addDays(3))) {
                $score += 15;
                $drivers[] = 'arrival_soon';
            }
        }

        $score = min(100, $score);
        $level = match (true) {
            $score >= 70 => 'critical',
            $score >= 50 => 'high',
            $score >= 30 => 'medium',
            default => 'low',
        };

        return [
            'is_at_risk' => $overdue || $score >= 50,
            'score' => $score,
            'level' => $level,
            'drivers' => $drivers,
            'action' => $overdue ? 'resolve_arrival' : ($balance > 0 ? 'secure_payment' : 'reconfirm'),
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
