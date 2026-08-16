<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\Reservation;
use Illuminate\Support\Collection;

final class DiscountRefundCashFlowService
{
    public function __construct(private readonly DepartmentRevenueService $departmentRevenue) {}

    /** @return array{period:array,summary:array,discount_sources:array,reasons:array,activity:array} */
    public function summary(ReportingPeriod $period): array
    {
        $start = $period->from->startOfDay();
        $end = $period->to->endOfDay();

        $folioDiscounts = FolioItem::query()
            ->where('type', 'discount')
            ->whereNull('pos_order_id')
            ->where('amount', '>', 0)
            ->whereBetween('charge_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->with(['reservation:id,guest_id,room_id', 'reservation.guest:id,first_name,last_name', 'reservation.room:id,room_number'])
            ->get();
        $posDiscounts = PosOrder::query()
            ->where('status', 'completed')
            ->where('discount_amount', '>', 0)
            ->where(function ($query) use ($period, $start, $end) {
                $query->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereBetween('paid_at', [$start, $end]))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereBetween('created_at', [$start, $end]));
            })
            ->get(['id', 'discount_amount', 'discount_reason', 'is_complimentary', 'business_date', 'paid_at', 'created_at']);
        $directDiscounts = Reservation::query()
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->where('direct_discount_amount', '>', 0)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('booked_at', [$start, $end])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('booked_at')->whereBetween('created_at', [$start, $end]));
            })
            ->with(['guest:id,first_name,last_name', 'room:id,room_number'])
            ->get(['id', 'guest_id', 'room_id', 'direct_discount_amount_base', 'booked_at', 'created_at']);

        $pmsRefunds = Payment::query()
            ->where('type', 'refund')
            ->notVoided()
            ->whereBetween('created_at', [$start, $end])
            ->with(['reservation:id,guest_id,room_id', 'reservation.guest:id,first_name,last_name', 'reservation.room:id,room_number'])
            ->get(['id', 'reservation_id', 'amount_base', 'method', 'created_at']);
        $posRefunds = PosOrderPayment::query()
            ->where('direction', 'out')
            ->whereBetween('paid_at', [$start, $end])
            ->with('order:id,refund_reason')
            ->get(['id', 'pos_order_id', 'amount', 'method', 'paid_at']);

        $pmsDiscountTotal = (float) $folioDiscounts->sum('amount_base') + (float) $directDiscounts->sum('direct_discount_amount_base');
        $discountTotal = round($pmsDiscountTotal + (float) $posDiscounts->sum('discount_amount'), 2);
        $refundTotal = round((float) $pmsRefunds->sum('amount_base') + (float) $posRefunds->sum('amount'), 2);

        // Leakage needs a scale to be judged against: discounts against the
        // period's net recognized revenue (the same figure the Department
        // Revenue report shows), refunds against what was actually collected.
        $revenueNet = round((float) $this->departmentRevenue->summary($period)['summary']['total'], 2);
        $collections = round(
            (float) Payment::query()
                ->notVoided()
                ->whereRaw("COALESCE(type, 'payment') IN ('payment', 'deposit')")
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount_base')
            + (float) PosOrderPayment::query()
                ->where('direction', 'in')
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount'),
            2,
        );

        $activity = collect()
            ->concat($folioDiscounts->map(fn (FolioItem $item) => [
                'key' => 'folio-'.$item->id, 'kind' => 'discount', 'source' => 'pms',
                'date' => $item->charge_date?->toDateString(), 'amount' => round((float) $item->amount_base, 2),
                'reason' => $item->description ?: '—', 'method' => null,
                'reference' => 'RES-'.$item->reservation_id, 'link_kind' => 'reservation', 'link_id' => $item->reservation_id,
                'counterparty' => trim(($item->reservation?->guest?->first_name ?? '').' '.($item->reservation?->guest?->last_name ?? '')) ?: '—',
            ]))
            ->concat($directDiscounts->map(fn (Reservation $reservation) => [
                'key' => 'direct-discount-'.$reservation->id, 'kind' => 'discount', 'source' => 'pms',
                'date' => ($reservation->booked_at ?? $reservation->created_at)?->toDateString(),
                'amount' => round((float) $reservation->direct_discount_amount_base, 2),
                'reason' => 'Ulje rezervimi direkt', 'method' => null,
                'reference' => 'RES-'.$reservation->id, 'link_kind' => 'reservation', 'link_id' => $reservation->id,
                'counterparty' => trim(($reservation->guest?->first_name ?? '').' '.($reservation->guest?->last_name ?? '')) ?: '—',
            ]))
            ->concat($posDiscounts->map(fn (PosOrder $order) => [
                'key' => 'pos-discount-'.$order->id, 'kind' => 'discount', 'source' => 'pos',
                'date' => ($order->business_date ?? $order->paid_at ?? $order->created_at)?->toDateString(),
                'amount' => round((float) $order->discount_amount, 2),
                'reason' => $order->discount_reason ?: ($order->is_complimentary ? 'Complimentary' : '—'), 'method' => null,
                'reference' => 'POS-'.$order->id, 'link_kind' => 'pos', 'link_id' => $order->id, 'counterparty' => 'POS',
            ]))
            ->concat($pmsRefunds->map(fn (Payment $payment) => [
                'key' => 'pms-refund-'.$payment->id, 'kind' => 'refund', 'source' => 'pms',
                'date' => $payment->created_at?->toDateString(), 'amount' => round((float) $payment->amount_base, 2),
                'reason' => 'Refund', 'method' => $payment->method,
                'reference' => 'RES-'.$payment->reservation_id, 'link_kind' => 'reservation', 'link_id' => $payment->reservation_id,
                'counterparty' => trim(($payment->reservation?->guest?->first_name ?? '').' '.($payment->reservation?->guest?->last_name ?? '')) ?: '—',
            ]))
            ->concat($posRefunds->map(fn (PosOrderPayment $payment) => [
                'key' => 'pos-refund-'.$payment->id, 'kind' => 'refund', 'source' => 'pos',
                'date' => $payment->paid_at?->toDateString(), 'amount' => round((float) $payment->amount, 2),
                'reason' => $payment->order?->refund_reason ?: 'Refund', 'method' => $payment->method,
                'reference' => 'POS-'.$payment->pos_order_id, 'link_kind' => 'pos', 'link_id' => $payment->pos_order_id, 'counterparty' => 'POS',
            ]))
            ->sortByDesc(fn (array $row) => $row['date'].'-'.$row['key'])->values();

        return [
            'period' => $period->toArray(),
            'summary' => [
                'discounts' => $discountTotal, 'refunds' => $refundTotal,
                'discount_count' => $folioDiscounts->count() + $directDiscounts->count() + $posDiscounts->count(),
                'refund_count' => $pmsRefunds->count() + $posRefunds->count(),
                'revenue_net' => $revenueNet,
                'discount_share' => $revenueNet > 0 ? round($discountTotal / $revenueNet * 100, 1) : null,
                'collections' => $collections,
                'refund_share' => $collections > 0 ? round($refundTotal / $collections * 100, 1) : null,
            ],
            'discount_sources' => [
                ['source' => 'pms', 'amount' => round($pmsDiscountTotal, 2), 'count' => $folioDiscounts->count() + $directDiscounts->count()],
                ['source' => 'pos', 'amount' => round((float) $posDiscounts->sum('discount_amount'), 2), 'count' => $posDiscounts->count()],
            ],
            'reasons' => $activity->where('kind', 'discount')->groupBy('reason')->map(fn (Collection $rows, string $reason) => [
                'reason' => $reason, 'amount' => round((float) $rows->sum('amount'), 2), 'count' => $rows->count(),
            ])->sortByDesc('amount')->values()->take(8)->all(),
            'activity' => $activity->all(),
        ];
    }
}
