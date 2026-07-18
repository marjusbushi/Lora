<?php

namespace App\Services\Reporting;

use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\User;
use Illuminate\Support\Collection;

final class PosControlReportService
{
    public function __construct(private readonly KpiCalculator $kpiCalculator) {}

    /** @return array{current:array,previous_period:array,changes:array} */
    public function withComparison(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previous = $this->summary($period->previousPeriod());

        return [
            'current' => $current,
            'previous_period' => $previous,
            'changes' => [
                'net_collected' => $this->kpiCalculator->change($current['summary']['net_collected'], $previous['summary']['net_collected']),
                'refund_total' => $this->kpiCalculator->change($current['summary']['refund_total'], $previous['summary']['refund_total']),
                'exception_rate' => $previous['summary']['order_population'] > 0
                    ? round($current['summary']['exception_rate'] - $previous['summary']['exception_rate'], 1)
                    : null,
            ],
        ];
    }

    public function summary(ReportingPeriod $period): array
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $payments = PosOrderPayment::query()
            ->whereBetween('paid_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->with('order:id,refund_reason')
            ->get(['id', 'pos_order_id', 'direction', 'method', 'amount', 'reference', 'paid_at', 'created_by']);

        $legacySales = $this->orderRange(
            PosOrder::query()->where('status', 'completed')->whereDoesntHave('payments', fn ($query) => $query->where('direction', 'in')),
            $period,
        )->get(['id', 'payment_method', 'total_amount', 'paid_at', 'created_at', 'created_by'])
            ->map(fn (PosOrder $order) => [
                'id' => "legacy-sale-{$order->id}", 'pos_order_id' => $order->id, 'direction' => 'in',
                'method' => $order->payment_method ?: '?', 'amount' => (float) $order->total_amount,
                'reference' => null, 'paid_at' => $order->paid_at ?? $order->created_at, 'created_by' => $order->created_by,
            ]);
        $legacyRefunds = PosOrder::query()
            ->whereBetween('refunded_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->whereDoesntHave('payments', fn ($query) => $query->where('direction', 'out'))
            ->get(['id', 'payment_method', 'total_amount', 'refunded_at', 'refunded_by', 'refund_reason', 'created_by'])
            ->map(fn (PosOrder $order) => [
                'id' => "legacy-refund-{$order->id}", 'pos_order_id' => $order->id, 'direction' => 'out',
                'method' => $order->payment_method ?: '?', 'amount' => (float) $order->total_amount,
                'reference' => null, 'reason' => $order->refund_reason, 'paid_at' => $order->refunded_at, 'created_by' => $order->refunded_by ?? $order->created_by,
            ]);
        $ledger = $payments->map(fn (PosOrderPayment $payment) => [
            'id' => $payment->id, 'pos_order_id' => $payment->pos_order_id, 'direction' => $payment->direction,
            'method' => $payment->method ?: '?', 'amount' => (float) $payment->amount,
            'reference' => $payment->reference, 'reason' => $payment->order?->refund_reason,
            'paid_at' => $payment->paid_at, 'created_by' => $payment->created_by,
        ])->concat($legacySales)->concat($legacyRefunds);

        $voids = PosOrder::query()->where('status', 'cancelled')
            ->where(fn ($query) => $query
                ->whereBetween('cancelled_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
                ->orWhere(fn ($legacy) => $legacy->whereNull('cancelled_at')->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])))
            ->get(['id', 'table_number', 'total_amount', 'cancelled_at', 'cancellation_reason', 'cancelled_by', 'created_by', 'created_at']);

        $operatorIds = $ledger->pluck('created_by')->concat($voids->pluck('cancelled_by'))->concat($voids->pluck('created_by'))->filter()->unique();
        $operators = User::query()->whereIn('id', $operatorIds)->pluck('name', 'id');
        $refunds = $ledger->where('direction', 'out')->map(fn (array $row) => [
            ...$row,
            'operator' => $operators->get($row['created_by'], '—'),
            'paid_at' => $row['paid_at']?->format('Y-m-d H:i'),
        ])->sortByDesc('paid_at')->values();
        $voidRows = $voids->map(fn (PosOrder $order) => [
            'id' => $order->id,
            'table_number' => $order->table_number,
            'amount' => round((float) $order->total_amount, 2),
            'reason' => $order->cancellation_reason,
            'operator' => $operators->get($order->cancelled_by ?? $order->created_by, '—'),
            'occurred_at' => ($order->cancelled_at ?? $order->created_at)?->format('Y-m-d H:i'),
        ])->sortByDesc('occurred_at')->values();

        $grossIn = round((float) $ledger->where('direction', 'in')->sum('amount'), 2);
        $refundTotal = round((float) $ledger->where('direction', 'out')->sum('amount'), 2);
        $saleOrders = $ledger->where('direction', 'in')->pluck('pos_order_id')->unique();
        $refundOrders = $refunds->pluck('pos_order_id')->unique();
        $exceptionOrders = $refundOrders->concat($voidRows->pluck('id'))->unique()->count();
        $population = $saleOrders->concat($refundOrders)->concat($voidRows->pluck('id'))->unique()->count();
        $methods = $ledger->groupBy('method')->map(function (Collection $rows, string $method) use ($grossIn) {
            $in = (float) $rows->where('direction', 'in')->sum('amount');
            $out = (float) $rows->where('direction', 'out')->sum('amount');

            return [
                'method' => $method,
                'orders' => $rows->where('direction', 'in')->pluck('pos_order_id')->unique()->count(),
                'gross' => round($in, 2),
                'refunds' => round($out, 2),
                'net' => round($in - $out, 2),
                'share' => $grossIn > 0 ? round($in / $grossIn * 100, 1) : 0.0,
            ];
        })->sortByDesc('gross')->values();

        $byOperator = $voidRows->map(fn (array $row) => ['operator' => $row['operator'], 'order_id' => $row['id'], 'kind' => 'void', 'void_value' => $row['amount'], 'refund_value' => 0.0])
            ->concat($refunds->map(fn (array $row) => ['operator' => $row['operator'], 'order_id' => $row['pos_order_id'], 'kind' => 'refund', 'void_value' => 0.0, 'refund_value' => $row['amount']]))
            ->groupBy('operator')->map(fn (Collection $rows, string $operator) => [
                'operator' => $operator,
                'voids' => $rows->where('kind', 'void')->pluck('order_id')->unique()->count(),
                'void_value' => round((float) $rows->sum('void_value'), 2),
                'refunds' => $rows->where('kind', 'refund')->pluck('order_id')->unique()->count(),
                'refund_value' => round((float) $rows->sum('refund_value'), 2),
            ])->sortByDesc(fn (array $row) => $row['void_value'] + $row['refund_value'])->values();

        return [
            'period' => $period->toArray(),
            'summary' => [
                'gross_collected' => $grossIn,
                'refund_total' => $refundTotal,
                'net_collected' => round($grossIn - $refundTotal, 2),
                'void_count' => $voidRows->count(),
                'void_value' => round((float) $voidRows->sum('amount'), 2),
                'missing_reason_count' => $voidRows->filter(fn (array $row) => blank($row['reason']))->count()
                    + $refunds->filter(fn (array $row) => blank($row['reason']))->pluck('pos_order_id')->unique()->count(),
                'order_population' => $population,
                'exception_rate' => $population > 0 ? round($exceptionOrders / $population * 100, 1) : 0.0,
            ],
            'methods' => $methods->all(),
            'voids' => $voidRows->all(),
            'refunds' => $refunds->all(),
            'operators' => $byOperator->all(),
        ];
    }

    private function orderRange($query, ReportingPeriod $period)
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        return $query->where(fn ($range) => $range
            ->where(fn ($business) => $business->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to))
            ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereBetween('paid_at', ["{$from} 00:00:00", "{$to} 23:59:59"]))
            ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])));
    }
}
