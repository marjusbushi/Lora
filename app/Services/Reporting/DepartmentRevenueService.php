<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use Carbon\CarbonPeriod;

final class DepartmentRevenueService
{
    public function __construct(private readonly RoomRevenueService $roomRevenue) {}

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

        foreach ($this->roomRevenue->summary($period)['daily'] as $date => $amount) {
            $row = $daily->get($date);
            $row['rooms'] = $amount;
            $daily->put($date, $row);
        }

        $folioRows = FolioItem::query()
            ->whereNull('pos_order_id')
            ->whereHas('reservation', fn ($query) => $query->where('status', '!=', 'cancelled')->whereNull('no_show_at'))
            ->whereBetween('charge_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNotIn('type', ['room', 'discount'])
            ->get(['id', 'reservation_id', 'type', 'amount_base', 'charge_date']);

        $orders = PosOrder::query()
            ->where('status', 'completed')
            ->where(function ($query) use ($period) {
                $query->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereBetween('paid_at', [$period->from->startOfDay(), $period->to->endOfDay()]))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()]));
            })
            ->get(['id', 'reservation_id', 'total_amount', 'business_date', 'paid_at', 'created_at']);
        $refunds = PosOrderPayment::query()
            ->where('direction', 'out')
            ->whereBetween('paid_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->with('order:id,reservation_id')
            ->get(['id', 'pos_order_id', 'amount', 'paid_at']);
        $legacyRefunds = PosOrder::query()
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->whereDoesntHave('payments', fn ($query) => $query->where('direction', 'out'))
            ->get(['id', 'reservation_id', 'total_amount', 'refunded_at']);
        $posOrderIds = $orders->pluck('id')
            ->merge($refunds->pluck('pos_order_id'))
            ->merge($legacyRefunds->pluck('id'))
            ->unique();
        $linkedPosReservations = FolioItem::query()
            ->whereIn('pos_order_id', $posOrderIds)
            ->whereNotNull('reservation_id')
            ->pluck('reservation_id', 'pos_order_id');
        foreach ($orders as $order) {
            if ($order->reservation_id) {
                $linkedPosReservations->put($order->id, $order->reservation_id);
            }
        }
        foreach ($refunds as $refund) {
            if ($refund->order?->reservation_id) {
                $linkedPosReservations->put($refund->pos_order_id, $refund->order->reservation_id);
            }
        }
        foreach ($legacyRefunds as $refund) {
            if ($refund->reservation_id) {
                $linkedPosReservations->put($refund->id, $refund->reservation_id);
            }
        }
        $factors = $this->roomRevenue->discountFactors(
            $folioRows->pluck('reservation_id')->merge($linkedPosReservations->values())->unique()->values()->all(),
        );

        foreach ($folioRows as $item) {
            $date = $item->charge_date?->toDateString();
            if (! $date || ! isset($daily[$date])) {
                continue;
            }
            // amount_base, not amount: a EUR-denominated extra summed at its
            // document value would corrupt the Lek totals (Nationality-bug class).
            $amount = round((float) $item->amount_base * ($factors[$item->reservation_id] ?? 1), 2);
            $row = $daily->get($date);
            $row['other'] = round($row['other'] + $amount, 2);
            $daily->put($date, $row);
        }

        foreach ($orders as $order) {
            $date = ($order->business_date ?? $order->paid_at ?? $order->created_at)?->toDateString();
            if ($date && isset($daily[$date])) {
                $row = $daily->get($date);
                $reservationId = $linkedPosReservations->get($order->id);
                $amount = (float) $order->total_amount * ($factors[$reservationId] ?? 1);
                $row['pos'] = round($row['pos'] + $amount, 2);
                $daily->put($date, $row);
            }
        }

        foreach ($refunds as $refund) {
            $date = $refund->paid_at?->toDateString();
            if ($date && isset($daily[$date])) {
                $row = $daily->get($date);
                $reservationId = $linkedPosReservations->get($refund->pos_order_id);
                $amount = (float) $refund->amount * ($factors[$reservationId] ?? 1);
                $row['pos'] = round($row['pos'] - $amount, 2);
                $daily->put($date, $row);
            }
        }

        foreach ($legacyRefunds as $refund) {
            $date = $refund->refunded_at?->toDateString();
            if ($date && isset($daily[$date])) {
                $row = $daily->get($date);
                $reservationId = $linkedPosReservations->get($refund->id);
                $amount = (float) $refund->total_amount * ($factors[$reservationId] ?? 1);
                $row['pos'] = round($row['pos'] - $amount, 2);
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
