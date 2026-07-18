<?php

namespace App\Services\Reporting;

use App\Models\FinancePayment;
use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final class DiscountRefundCashFlowService
{
    /** @return array{period:array,summary:array,daily:array,discount_sources:array,reasons:array,activity:array} */
    public function summary(ReportingPeriod $period): array
    {
        $start = $period->from->startOfDay();
        $end = $period->to->endOfDay();

        $folioDiscounts = FolioItem::query()
            ->where('type', 'discount')
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

        $pmsRefunds = Payment::query()
            ->where('type', 'refund')
            ->notVoided()
            ->whereBetween('created_at', [$start, $end])
            ->with(['reservation:id,guest_id,room_id', 'reservation.guest:id,first_name,last_name', 'reservation.room:id,room_number'])
            ->get(['id', 'reservation_id', 'amount', 'method', 'created_at']);
        $posRefunds = PosOrderPayment::query()
            ->where('direction', 'out')
            ->whereBetween('paid_at', [$start, $end])
            ->with('order:id,refund_reason')
            ->get(['id', 'pos_order_id', 'amount', 'method', 'paid_at']);

        $ledger = FinancePayment::query()
            ->whereBetween('paid_at', [$start, $end])
            ->whereIn('direction', ['in', 'out'])
            ->get(['id', 'direction', 'amount_base', 'method', 'description', 'paid_at', 'invoice_id', 'bill_id']);

        $discountTotal = round((float) $folioDiscounts->sum('amount') + (float) $posDiscounts->sum('discount_amount'), 2);
        $refundTotal = round((float) $pmsRefunds->sum('amount') + (float) $posRefunds->sum('amount'), 2);
        $inflow = round((float) $ledger->where('direction', 'in')->sum('amount_base'), 2);
        $outflow = round((float) $ledger->where('direction', 'out')->sum('amount_base'), 2);

        $activity = collect()
            ->concat($folioDiscounts->map(fn (FolioItem $item) => [
                'key' => 'folio-'.$item->id, 'kind' => 'discount', 'source' => 'pms',
                'date' => $item->charge_date?->toDateString(), 'amount' => round((float) $item->amount, 2),
                'reason' => $item->description ?: '—', 'method' => null,
                'reference' => 'RES-'.$item->reservation_id, 'link_kind' => 'reservation', 'link_id' => $item->reservation_id,
                'counterparty' => trim(($item->reservation?->guest?->first_name ?? '').' '.($item->reservation?->guest?->last_name ?? '')) ?: '—',
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
                'date' => $payment->created_at?->toDateString(), 'amount' => round((float) $payment->amount, 2),
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
                'inflow' => $inflow, 'outflow' => $outflow, 'net_cash_flow' => round($inflow - $outflow, 2),
                'discount_count' => $folioDiscounts->count() + $posDiscounts->count(),
                'refund_count' => $pmsRefunds->count() + $posRefunds->count(),
            ],
            'daily' => $this->daily($period, $ledger),
            'discount_sources' => [
                ['source' => 'pms', 'amount' => round((float) $folioDiscounts->sum('amount'), 2), 'count' => $folioDiscounts->count()],
                ['source' => 'pos', 'amount' => round((float) $posDiscounts->sum('discount_amount'), 2), 'count' => $posDiscounts->count()],
            ],
            'reasons' => $activity->where('kind', 'discount')->groupBy('reason')->map(fn (Collection $rows, string $reason) => [
                'reason' => $reason, 'amount' => round((float) $rows->sum('amount'), 2), 'count' => $rows->count(),
            ])->sortByDesc('amount')->values()->take(8)->all(),
            'activity' => $activity->all(),
        ];
    }

    private function daily(ReportingPeriod $period, Collection $ledger): array
    {
        return collect(CarbonPeriod::create($period->from, $period->to))->map(function ($day) use ($ledger) {
            $date = $day->toDateString();
            $rows = $ledger->filter(fn (FinancePayment $payment) => $payment->paid_at?->toDateString() === $date);
            $in = round((float) $rows->where('direction', 'in')->sum('amount_base'), 2);
            $out = round((float) $rows->where('direction', 'out')->sum('amount_base'), 2);

            return ['date' => $date, 'inflow' => $in, 'outflow' => $out, 'net' => round($in - $out, 2)];
        })->all();
    }
}
