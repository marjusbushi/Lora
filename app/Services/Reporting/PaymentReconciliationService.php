<?php

namespace App\Services\Reporting;

use App\Models\FinancePayment;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PaymentReconciliationService
{
    /** @return array{period:array,summary:array,methods:array,sources:array,daily:array,issues:array} */
    public function summary(ReportingPeriod $period): array
    {
        $start = $period->from->startOfDay();
        $end = $period->to->endOfDay();

        $payments = Payment::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['id', 'reservation_id', 'amount', 'amount_base', 'method', 'type', 'is_voided', 'created_at']);
        $supportedPmsMethods = ['cash', 'card'];
        $activePayments = $payments->filter(fn (Payment $payment) => ! $payment->is_voided
            && (float) $payment->amount_base > 0
            && in_array($payment->method, $supportedPmsMethods, true)
            && in_array($payment->type ?? 'payment', ['payment', 'deposit'], true));
        $refunds = $payments->filter(fn (Payment $payment) => ! $payment->is_voided
            && in_array($payment->method, $supportedPmsMethods, true)
            && ($payment->type ?? 'payment') === 'refund');
        $voided = $payments->filter(fn (Payment $payment) => $payment->is_voided
            && in_array($payment->method, $supportedPmsMethods, true));

        $posPayments = PosOrderPayment::query()
            ->whereBetween('paid_at', [$start, $end])
            ->with('order:id,reservation_id')
            ->get([
                'id', 'pos_order_id', 'pos_shift_id', 'direction', 'method',
                'amount', 'paid_at',
            ]);
        $legacyPosOrders = $this->posOrdersFor($period)
            ->whereDoesntHave('payments', fn (Builder $query) => $query->where('direction', 'in'))
            ->get([
                'id', 'reservation_id', 'status', 'payment_method', 'total_amount',
                'pos_shift_id', 'paid_at', 'business_date', 'updated_at',
            ]);
        $closedShifts = PosShift::query()
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$start, $end])
            ->with([
                'user:id,name',
                'payments:id,pos_shift_id,direction',
                'orders' => fn ($query) => $query
                    ->where('status', 'completed')
                    ->with('payments:id,pos_order_id,direction')
                    ->select(['id', 'pos_shift_id', 'payment_method', 'total_amount']),
            ])
            ->get([
                'id', 'user_id', 'opening_float', 'closed_at', 'expected_cash',
                'counted_cash', 'over_short', 'cash_sales',
            ]);

        $paymentLedger = FinancePayment::query()
            ->where('sourceable_type', Payment::class)
            ->whereIn('sourceable_id', $activePayments->pluck('id')->concat($refunds->pluck('id')))
            ->get(['id', 'sourceable_id', 'direction', 'amount', 'amount_base'])
            ->keyBy('sourceable_id');
        $shiftLedger = FinancePayment::query()
            ->where('sourceable_type', PosShift::class)
            ->whereIn('sourceable_id', $closedShifts->pluck('id'))
            ->get(['id', 'sourceable_id', 'direction', 'amount', 'amount_base'])
            ->keyBy('sourceable_id');
        $posLedger = FinancePayment::query()
            ->where('sourceable_type', PosOrderPayment::class)
            ->whereIn('sourceable_id', $posPayments->whereIn('method', ['cash', 'card'])->pluck('id'))
            ->get(['id', 'sourceable_id', 'direction', 'amount', 'amount_base'])
            ->keyBy('sourceable_id');

        $issues = collect();
        $expectedSources = 0;
        $matchedSources = 0;
        $unpostedTotal = 0.0;

        foreach ($activePayments as $payment) {
            $expectedSources++;
            $ledger = $paymentLedger->get($payment->id);
            $expected = round((float) $payment->amount_base, 2);
            $actual = $ledger && $ledger->direction === 'in' ? round((float) $ledger->amount_base, 2) : 0.0;

            if ($ledger && abs($expected - $actual) < 0.01) {
                $matchedSources++;
            } else {
                $unpostedTotal += $expected;
                $issues->push($this->issue(
                    $ledger ? 'ledger_mismatch' : 'missing_ledger',
                    'pms',
                    'PMS-'.$payment->id,
                    $payment->created_at?->toDateString(),
                    $expected,
                    $actual,
                    'error',
                    'reservation',
                    $payment->reservation_id,
                ));
            }
        }

        foreach ($refunds as $refund) {
            $expectedSources++;
            $ledger = $paymentLedger->get($refund->id);
            $expected = round((float) $refund->amount_base, 2);
            $actual = $ledger && $ledger->direction === 'out' ? round((float) $ledger->amount_base, 2) : 0.0;

            if ($ledger && abs($expected - $actual) < 0.01) {
                $matchedSources++;
            } else {
                $unpostedTotal += $expected;
                $issues->push($this->issue(
                    $ledger ? 'ledger_mismatch' : 'missing_ledger',
                    'pms',
                    'PMS-REFUND-'.$refund->id,
                    $refund->created_at?->toDateString(),
                    $expected,
                    $actual,
                    'error',
                    'reservation',
                    $refund->reservation_id,
                ));
            }
        }

        foreach ($posPayments->whereIn('method', ['cash', 'card']) as $posPayment) {
            $expectedSources++;
            $ledger = $posLedger->get($posPayment->id);
            $expected = round((float) $posPayment->amount, 2);
            $actual = $ledger && $ledger->direction === $posPayment->direction
                ? round((float) $ledger->amount, 2)
                : 0.0;

            if ($ledger && abs($expected - $actual) < 0.01) {
                $matchedSources++;
            } else {
                $unpostedTotal += $expected;
                $issues->push($this->issue(
                    $ledger ? 'ledger_mismatch' : 'missing_ledger',
                    'pos',
                    'POS-PAY-'.$posPayment->id,
                    $posPayment->paid_at?->toDateString(),
                    $expected,
                    $actual,
                    'error',
                    'pos',
                    $posPayment->pos_order_id,
                ));
            }
        }

        foreach ($closedShifts as $shift) {
            $legacyCash = (float) $shift->orders
                ->filter(fn (PosOrder $order) => ! $order->payments->contains('direction', 'in'))
                ->where('payment_method', 'cash')
                ->sum('total_amount');
            $hasNewTenders = $shift->payments->contains('direction', 'in');
            $yield = $hasNewTenders || $legacyCash > 0
                ? round($legacyCash + (float) $shift->over_short, 2)
                : ($shift->counted_cash !== null
                    ? round((float) $shift->counted_cash - (float) $shift->opening_float, 2)
                    : round((float) $shift->cash_sales, 2));
            if (abs($yield) >= 0.01) {
                $expectedSources++;
                $ledger = $shiftLedger->get($shift->id);
                $expectedDirection = $yield > 0 ? 'in' : 'out';
                $actual = $ledger && $ledger->direction === $expectedDirection ? round((float) $ledger->amount, 2) : 0.0;

                if ($ledger && abs(abs($yield) - $actual) < 0.01) {
                    $matchedSources++;
                } else {
                    $unpostedTotal += abs($yield);
                    $issues->push($this->issue(
                        $ledger ? 'ledger_mismatch' : 'missing_ledger',
                        'pos_shift',
                        'SHIFT-'.$shift->id,
                        $shift->closed_at?->toDateString(),
                        abs($yield),
                        $actual,
                        'error',
                        'pos',
                        null,
                    ));
                }
            }

            if (abs((float) $shift->over_short) >= 0.01) {
                $issues->push($this->issue(
                    'cash_variance',
                    'pos_shift',
                    'SHIFT-'.$shift->id,
                    $shift->closed_at?->toDateString(),
                    (float) $shift->expected_cash,
                    (float) $shift->counted_cash,
                    abs((float) $shift->over_short) >= 5 ? 'error' : 'warning',
                    'pos',
                    null,
                ));
            }
        }

        foreach ($legacyPosOrders as $order) {
            if (! $order->payment_method) {
                $issues->push($this->issue('missing_method', 'pos', 'POS-'.$order->id, $this->settlementDate($order), (float) $order->total_amount, 0, 'error', 'pos', $order->id));
            }
            if (! $order->business_date && ! $order->paid_at) {
                $issues->push($this->issue('legacy_settlement_date', 'pos', 'POS-'.$order->id, $this->settlementDate($order), (float) $order->total_amount, 0, 'warning', 'pos', $order->id));
            }
            if ($order->payment_method === 'room_charge' && ! $order->reservation_id) {
                $issues->push($this->issue('unallocated_room_charge', 'pos', 'POS-'.$order->id, $this->settlementDate($order), (float) $order->total_amount, 0, 'error', 'pos', $order->id));
            }
        }

        foreach ($posPayments->where('method', 'room_charge') as $posPayment) {
            if (! $posPayment->order?->reservation_id) {
                $issues->push($this->issue(
                    'unallocated_room_charge',
                    'pos',
                    'POS-PAY-'.$posPayment->id,
                    $posPayment->paid_at?->toDateString(),
                    (float) $posPayment->amount,
                    0,
                    'error',
                    'pos',
                    $posPayment->pos_order_id,
                ));
            }
        }

        $pmsCash = $this->paymentAmount($activePayments, $refunds, 'cash');
        $pmsCard = $this->paymentAmount($activePayments, $refunds, 'card');
        $posCash = $this->posPaymentAmount($posPayments, $legacyPosOrders, 'cash');
        $posCard = $this->posPaymentAmount($posPayments, $legacyPosOrders, 'card');
        $roomCharge = $this->posPaymentAmount($posPayments, $legacyPosOrders, 'room_charge');
        $cash = round($pmsCash + $posCash, 2);
        $card = round($pmsCard + $posCard, 2);
        $collected = round($cash + $card, 2);

        $daily = $this->dailyRows($period, $activePayments, $refunds, $posPayments, $legacyPosOrders);
        $methods = collect([
            ['method' => 'cash', 'amount' => $cash],
            ['method' => 'card', 'amount' => $card],
        ])->map(fn (array $method) => [
            ...$method,
            'share' => $collected > 0 ? round($method['amount'] / $collected * 100, 1) : 0.0,
        ])->all();

        return [
            'period' => $period->toArray(),
            'summary' => [
                'collected' => $collected,
                'cash' => $cash,
                'card' => $card,
                'room_charge' => $roomCharge,
                'refunds' => round((float) $refunds->sum('amount_base') + (float) $posPayments->where('direction', 'out')->sum('amount'), 2),
                'voided' => round((float) $voided->sum('amount_base'), 2),
                'transaction_count' => $activePayments->count()
                    + $refunds->count()
                    + $posPayments->whereIn('method', ['cash', 'card'])->count()
                    + $legacyPosOrders->whereIn('payment_method', ['cash', 'card'])->count(),
                'expected_sources' => $expectedSources,
                'matched_sources' => $matchedSources,
                'reconciliation_rate' => $expectedSources > 0 ? round($matchedSources / $expectedSources * 100, 1) : 100.0,
                'issues_count' => $issues->count(),
                'unposted_total' => round($unpostedTotal, 2),
                'cash_variance' => round((float) $closedShifts->sum('over_short'), 2),
            ],
            'methods' => $methods,
            'sources' => [
                ['source' => 'pms', 'cash' => $pmsCash, 'card' => $pmsCard, 'total' => round($pmsCash + $pmsCard, 2), 'count' => $activePayments->count() + $refunds->count()],
                ['source' => 'pos', 'cash' => $posCash, 'card' => $posCard, 'total' => round($posCash + $posCard, 2), 'count' => $posPayments->whereIn('method', ['cash', 'card'])->count() + $legacyPosOrders->whereIn('payment_method', ['cash', 'card'])->count()],
            ],
            'daily' => $daily,
            'issues' => $issues->sortBy([
                fn (array $a, array $b) => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1),
                fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']),
            ])->values()->all(),
        ];
    }

    private function posOrdersFor(ReportingPeriod $period): Builder
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $start = $period->from->startOfDay();
        $end = $period->to->endOfDay();

        return PosOrder::query()->where('status', 'completed')
            ->where(function (Builder $query) use ($from, $to, $start, $end) {
                $query->where(function (Builder $businessDate) use ($from, $to) {
                    $businessDate->whereNotNull('business_date')
                        ->whereDate('business_date', '>=', $from)
                        ->whereDate('business_date', '<=', $to);
                })->orWhere(function (Builder $paidAt) use ($start, $end) {
                    $paidAt->whereNull('business_date')->whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end]);
                })->orWhere(function (Builder $legacy) use ($start, $end) {
                    $legacy->whereNull('business_date')->whereNull('paid_at')->whereBetween('updated_at', [$start, $end]);
                });
            });
    }

    private function settlementDate(PosOrder $order): string
    {
        return $order->business_date?->toDateString()
            ?? $order->paid_at?->toDateString()
            ?? $order->updated_at?->toDateString();
    }

    private function paymentAmount(Collection $payments, Collection $refunds, string $method): float
    {
        return round(
            (float) $payments->where('method', $method)->sum('amount_base')
            - (float) $refunds->where('method', $method)->sum('amount_base'),
            2,
        );
    }

    private function posPaymentAmount(Collection $payments, Collection $legacyOrders, string $method): float
    {
        return round(
            (float) $payments->where('method', $method)->where('direction', 'in')->sum('amount')
            - (float) $payments->where('method', $method)->where('direction', 'out')->sum('amount')
            + (float) $legacyOrders->where('payment_method', $method)->sum('total_amount'),
            2,
        );
    }

    private function dailyRows(ReportingPeriod $period, Collection $payments, Collection $refunds, Collection $posPayments, Collection $legacyOrders): array
    {
        $daily = [];
        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $daily[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'pms_cash' => 0.0,
                'pms_card' => 0.0,
                'pos_cash' => 0.0,
                'pos_card' => 0.0,
                'room_charge' => 0.0,
                'total' => 0.0,
            ];
        }
        foreach ($payments as $payment) {
            $key = $payment->created_at->toDateString();
            if (isset($daily[$key]) && in_array($payment->method, ['cash', 'card'], true)) {
                $daily[$key]['pms_'.$payment->method] += (float) $payment->amount_base;
            }
        }
        foreach ($refunds as $refund) {
            $key = $refund->created_at->toDateString();
            if (isset($daily[$key]) && in_array($refund->method, ['cash', 'card'], true)) {
                $daily[$key]['pms_'.$refund->method] -= (float) $refund->amount_base;
            }
        }
        foreach ($posPayments as $payment) {
            $key = $payment->paid_at?->toDateString();
            $amount = ($payment->direction === 'out' ? -1 : 1) * (float) $payment->amount;
            if (isset($daily[$key]) && in_array($payment->method, ['cash', 'card'], true)) {
                $daily[$key]['pos_'.$payment->method] += $amount;
            } elseif (isset($daily[$key]) && $payment->method === 'room_charge') {
                $daily[$key]['room_charge'] += $amount;
            }
        }
        foreach ($legacyOrders as $order) {
            $key = $this->settlementDate($order);
            if (isset($daily[$key]) && in_array($order->payment_method, ['cash', 'card'], true)) {
                $daily[$key]['pos_'.$order->payment_method] += (float) $order->total_amount;
            } elseif (isset($daily[$key]) && $order->payment_method === 'room_charge') {
                $daily[$key]['room_charge'] += (float) $order->total_amount;
            }
        }

        return collect($daily)->map(function (array $row) {
            foreach (['pms_cash', 'pms_card', 'pos_cash', 'pos_card', 'room_charge'] as $key) {
                $row[$key] = round($row[$key], 2);
            }
            $row['total'] = round($row['pms_cash'] + $row['pms_card'] + $row['pos_cash'] + $row['pos_card'], 2);

            return $row;
        })->values()->all();
    }

    private function issue(
        string $type,
        string $source,
        string $reference,
        ?string $date,
        float $expected,
        float $actual,
        string $severity,
        string $linkKind,
        ?int $linkId,
    ): array {
        return [
            'type' => $type,
            'source' => $source,
            'reference' => $reference,
            'date' => $date,
            'expected' => round($expected, 2),
            'actual' => round($actual, 2),
            'difference' => round($actual - $expected, 2),
            'severity' => $severity,
            'link_kind' => $linkKind,
            'link_id' => $linkId,
        ];
    }
}
