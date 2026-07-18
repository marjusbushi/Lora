<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

final class BookingBehaviorService
{
    private const LEAD_BUCKETS = [
        'same_day' => [0, 0],
        'one_to_three' => [1, 3],
        'four_to_seven' => [4, 7],
        'eight_to_fourteen' => [8, 14],
        'fifteen_to_thirty' => [15, 30],
        'thirty_one_to_sixty' => [31, 60],
        'sixty_one_plus' => [61, null],
    ];

    private const LOS_BUCKETS = [
        'zero_nights' => [0, 0],
        'one_night' => [1, 1],
        'two_nights' => [2, 2],
        'three_to_four' => [3, 4],
        'five_to_seven' => [5, 7],
        'eight_plus' => [8, null],
    ];

    public function __construct(private readonly KpiCalculator $kpiCalculator) {}

    /** @return array{period:array,summary:array,lead_buckets:array,los_buckets:array,channels:array} */
    public function summary(ReportingPeriod $period): array
    {
        $records = Reservation::query()
            ->whereDate('check_in_date', '>=', $period->from->toDateString())
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->get(['id', 'channel', 'check_in_date', 'check_out_date', 'created_at'])
            ->map(function (Reservation $reservation) {
                $created = CarbonImmutable::parse($reservation->created_at)->startOfDay();
                $checkIn = CarbonImmutable::parse($reservation->check_in_date)->startOfDay();
                $checkOut = CarbonImmutable::parse($reservation->check_out_date)->startOfDay();

                return [
                    'channel' => Reservation::normalizeChannel($reservation->channel),
                    'lead' => max(0, (int) $created->diffInDays($checkIn, false)),
                    'los' => max(0, (int) $checkIn->diffInDays($checkOut, false)),
                ];
            });

        $count = $records->count();
        $leadValues = $records->pluck('lead')->all();
        $losValues = $records->pluck('los')->all();

        $channels = $records->groupBy('channel')
            ->map(function ($channelRecords, string $channel) use ($count) {
                $channelCount = $channelRecords->count();

                return [
                    'channel' => $channel,
                    'count' => $channelCount,
                    'share' => $count > 0 ? round($channelCount / $count * 100, 1) : 0,
                    'avg_lead' => $channelCount > 0 ? round((float) $channelRecords->avg('lead'), 1) : 0,
                    'median_lead' => $this->median($channelRecords->pluck('lead')->all()),
                    'avg_los' => $channelCount > 0 ? round((float) $channelRecords->avg('los'), 1) : 0,
                    'long_stay_share' => $channelCount > 0
                        ? round($channelRecords->where('los', '>=', 5)->count() / $channelCount * 100, 1)
                        : 0,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'period' => $period->toArray(),
            'summary' => [
                'count' => $count,
                'avg_lead' => $count > 0 ? round((float) $records->avg('lead'), 1) : 0,
                'median_lead' => $this->median($leadValues),
                'avg_los' => $count > 0 ? round((float) $records->avg('los'), 1) : 0,
                'median_los' => $this->median($losValues),
                'same_day_share' => $count > 0 ? round($records->where('lead', 0)->count() / $count * 100, 1) : 0,
                'long_stay_share' => $count > 0 ? round($records->where('los', '>=', 5)->count() / $count * 100, 1) : 0,
            ],
            'lead_buckets' => $this->bucket($leadValues, self::LEAD_BUCKETS),
            'los_buckets' => $this->bucket($losValues, self::LOS_BUCKETS),
            'channels' => $channels,
        ];
    }

    /** @return array{current:array,previous_period:array,changes:array} */
    public function withComparisons(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previous = $this->summary($period->previousPeriod());
        $hasPrevious = $previous['summary']['count'] > 0;

        return [
            'current' => $current,
            'previous_period' => $previous,
            'changes' => [
                'count' => $this->kpiCalculator->change(
                    (float) $current['summary']['count'],
                    (float) $previous['summary']['count'],
                ),
                'avg_lead' => $hasPrevious
                    ? round($current['summary']['avg_lead'] - $previous['summary']['avg_lead'], 1)
                    : null,
                'avg_los' => $hasPrevious
                    ? round($current['summary']['avg_los'] - $previous['summary']['avg_los'], 1)
                    : null,
                'same_day_share' => $hasPrevious
                    ? round($current['summary']['same_day_share'] - $previous['summary']['same_day_share'], 1)
                    : null,
            ],
        ];
    }

    /** @param array<string, array{0:int,1:?int}> $definitions */
    private function bucket(array $values, array $definitions): array
    {
        $total = count($values);

        return collect($definitions)->map(function (array $range, string $key) use ($values, $total) {
            [$min, $max] = $range;
            $count = collect($values)->filter(fn (int $value) => $value >= $min && ($max === null || $value <= $max))->count();

            return [
                'key' => $key,
                'count' => $count,
                'share' => $total > 0 ? round($count / $total * 100, 1) : 0,
            ];
        })->values()->all();
    }

    private function median(array $values): float
    {
        if ($values === []) {
            return 0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? round(($values[$middle - 1] + $values[$middle]) / 2, 1)
            : round((float) $values[$middle], 1);
    }
}
