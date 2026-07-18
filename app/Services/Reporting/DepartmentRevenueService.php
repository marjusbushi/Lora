<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\PosOrder;
use App\Models\Reservation;
use Carbon\CarbonPeriod;

final class DepartmentRevenueService
{
    public function __construct(private readonly StayRevenueAllocator $allocator) {}

    /** @return array{current:array,previous:array,changes:array} */
    public function withComparison(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previous = $this->summary($period->previousPeriod());

        return [
            'current' => $current,
            'previous' => $previous,
            'changes' => collect(['total', 'rooms', 'pos', 'other'])->mapWithKeys(fn (string $key) => [
                $key => $this->change($current['summary'][$key], $previous['summary'][$key]),
            ])->all(),
        ];
    }

    /** @return array{period:array,summary:array,departments:array,daily:array} */
    public function summary(ReportingPeriod $period): array
    {
        $daily = collect(CarbonPeriod::create($period->from, $period->to))->mapWithKeys(fn ($day) => [
            $day->toDateString() => ['date' => $day->toDateString(), 'rooms' => 0.0, 'pos' => 0.0, 'other' => 0.0, 'total' => 0.0],
        ]);

        $reservations = Reservation::query()
            ->where('status', '!=', 'cancelled')->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'check_in_date', 'check_out_date', 'total_amount']);
        foreach ($reservations as $reservation) {
            foreach ($this->allocator->allocate($reservation->check_in_date, $reservation->check_out_date, $reservation->total_amount, $period) as $date => $amount) {
                $row = $daily->get($date);
                $row['rooms'] = round($row['rooms'] + $amount, 2);
                $daily->put($date, $row);
            }
        }

        $folioRows = FolioItem::query()
            ->whereNull('pos_order_id')
            ->whereHas('reservation', fn ($query) => $query->where('status', '!=', 'cancelled')->whereNull('no_show_at'))
            ->whereBetween('charge_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->where('type', '!=', 'room')
            ->get(['id', 'type', 'amount', 'charge_date']);
        foreach ($folioRows as $item) {
            $date = $item->charge_date?->toDateString();
            if (! $date || ! isset($daily[$date])) {
                continue;
            }
            $amount = round((float) $item->amount, 2);
            $row = $daily->get($date);
            if ($item->type === 'discount') {
                $row['rooms'] = round($row['rooms'] - abs($amount), 2);
            } else {
                $row['other'] = round($row['other'] + $amount, 2);
            }
            $daily->put($date, $row);
        }

        $orders = PosOrder::query()
            ->where('status', 'completed')
            ->where(function ($query) use ($period) {
                $query->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereBetween('paid_at', [$period->from->startOfDay(), $period->to->endOfDay()]))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()]));
            })
            ->get(['id', 'total_amount', 'business_date', 'paid_at', 'created_at']);
        foreach ($orders as $order) {
            $date = ($order->business_date ?? $order->paid_at ?? $order->created_at)?->toDateString();
            if ($date && isset($daily[$date])) {
                $row = $daily->get($date);
                $row['pos'] = round($row['pos'] + (float) $order->total_amount, 2);
                $daily->put($date, $row);
            }
        }

        $refunds = PosOrder::query()
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->get(['id', 'total_amount', 'refunded_at']);
        foreach ($refunds as $order) {
            $date = $order->refunded_at?->toDateString();
            if ($date && isset($daily[$date])) {
                $row = $daily->get($date);
                $row['pos'] = round($row['pos'] - (float) $order->total_amount, 2);
                $daily->put($date, $row);
            }
        }

        $daily = $daily->map(function (array $row) {
            $row['total'] = round($row['rooms'] + $row['pos'] + $row['other'], 2);

            return $row;
        })->values();
        $totals = [
            'rooms' => round((float) $daily->sum('rooms'), 2),
            'pos' => round((float) $daily->sum('pos'), 2),
            'other' => round((float) $daily->sum('other'), 2),
        ];
        $totals['total'] = round(array_sum($totals), 2);

        return [
            'period' => $period->toArray(),
            'summary' => $totals,
            'departments' => collect(['rooms', 'pos', 'other'])->map(fn (string $key) => [
                'department' => $key,
                'amount' => $totals[$key],
                'share' => $totals['total'] > 0 ? round($totals[$key] / $totals['total'] * 100, 1) : 0.0,
            ])->all(),
            'daily' => $daily->all(),
        ];
    }

    private function change(float $current, float $previous): ?float
    {
        return abs($previous) < 0.01 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }
}
