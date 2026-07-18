<?php

namespace App\Services\Reporting;

use Illuminate\Support\Collection;

final class GuestSegmentationService
{
    public function __construct(private readonly GuestLifetimeValueService $lifetimeValue) {}

    /** @return array{as_of:string,summary:array,segments:array,guests:array,rules:array} */
    public function summary(string $activeSegment = 'all'): array
    {
        $rows = collect($this->lifetimeValue->summary(null)['guests']);
        $vipThreshold = $this->percentile($rows->pluck('net_value'), 0.8);
        $segmented = $rows->map(function (array $row) use ($vipThreshold) {
            $segment = $this->segment($row, $vipThreshold);

            return $row + [
                'segment_360' => $segment,
                'next_action' => match ($segment) {
                    'vip' => 'retain',
                    'loyal' => 'reward',
                    'returning' => 'convert',
                    'new' => 'second_stay',
                    default => 'win_back',
                },
            ];
        });
        $totalValue = (float) $segmented->sum('net_value');
        $segments = collect(['vip', 'loyal', 'returning', 'new', 'dormant'])->map(function (string $segment) use ($segmented, $totalValue) {
            $members = $segmented->where('segment_360', $segment);

            return [
                'key' => $segment,
                'guests' => $members->count(),
                'net_value' => round((float) $members->sum('net_value'), 2),
                'average_value' => $members->isEmpty() ? 0.0 : round((float) $members->avg('net_value'), 2),
                'value_share' => $totalValue > 0 ? round((float) $members->sum('net_value') / $totalValue * 100, 1) : 0.0,
            ];
        });

        $visibleGuests = $activeSegment === 'all'
            ? $segmented
            : $segmented->where('segment_360', $activeSegment);

        return [
            'as_of' => today()->toDateString(),
            'active_segment' => $activeSegment,
            'summary' => [
                'total_guests' => $segmented->count(),
                'active_guests' => $segmented->where('days_since_last', '<=', 365)->count(),
                'vip_guests' => $segmented->where('segment_360', 'vip')->count(),
                'dormant_guests' => $segmented->where('segment_360', 'dormant')->count(),
                'segmented_value' => round($totalValue, 2),
                'vip_threshold' => round($vipThreshold, 2),
            ],
            'segments' => $segments->all(),
            'guests' => $visibleGuests->sort(function (array $left, array $right) {
                $order = ['vip', 'loyal', 'returning', 'new', 'dormant'];
                $segmentOrder = array_search($left['segment_360'], $order, true) <=> array_search($right['segment_360'], $order, true);

                return $segmentOrder !== 0 ? $segmentOrder : $right['net_value'] <=> $left['net_value'];
            })->take(150)->values()->all(),
            'rules' => [
                'vip_percentile' => 80,
                'active_days' => 365,
                'new_days' => 180,
            ],
        ];
    }

    private function segment(array $row, float $vipThreshold): string
    {
        $days = (int) ($row['days_since_last'] ?? PHP_INT_MAX);
        if ($days <= 365 && $row['net_value'] >= $vipThreshold && $row['stays'] >= 2) {
            return 'vip';
        }
        if ($days <= 365 && $row['stays'] >= 3) {
            return 'loyal';
        }
        if ($days <= 365 && $row['stays'] === 2) {
            return 'returning';
        }
        if ($days <= 180 && $row['stays'] === 1) {
            return 'new';
        }

        return 'dormant';
    }

    private function percentile(Collection $values, float $percentile): float
    {
        $sorted = $values->map(fn ($value) => (float) $value)->sort()->values();
        if ($sorted->isEmpty()) {
            return 0.0;
        }

        return (float) $sorted->get(max(0, (int) ceil($sorted->count() * $percentile) - 1));
    }
}
