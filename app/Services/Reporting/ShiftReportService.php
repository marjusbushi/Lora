<?php

namespace App\Services\Reporting;

use App\Models\Payment;
use App\Models\PosShift;

final class ShiftReportService
{
    /** @return array{shifts:array,totals:array} */
    public function summary(ReportingPeriod $period): array
    {
        $closedShifts = PosShift::query()
            ->with('user:id,name')
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->orderByDesc('closed_at')
            ->get();

        // PMS cash taken at the desk during each shift's window: the drawer
        // physically receives it, but the POS expectation (float + POS cash
        // sales) knows nothing about it — on Saturn this was the entire
        // +4,465 L cumulative "over". Shown as context so the variance is
        // explainable, without changing the drawer math.
        $earliestOpen = $closedShifts->min('opened_at');
        $pmsCashPayments = $earliestOpen
            ? Payment::query()->notVoided()
                ->where('method', 'cash')
                ->whereRaw("COALESCE(type, 'payment') IN ('payment', 'deposit')")
                ->whereBetween('created_at', [$earliestOpen, $period->to->endOfDay()])
                ->get(['id', 'amount_base', 'created_at'])
            : collect();

        $shifts = $closedShifts
            ->map(function (PosShift $shift) use ($pmsCashPayments) {
                $opening = round((float) $shift->opening_float, 2);
                $cash = round((float) $shift->cash_sales, 2);
                $card = round((float) $shift->card_sales, 2);
                $roomCharge = round((float) $shift->room_charge_sales, 2);
                $expected = round((float) $shift->expected_cash, 2);
                $counted = round((float) $shift->counted_cash, 2);
                $overShort = round((float) $shift->over_short, 2);
                $total = round((float) $shift->total_sales, 2);
                $pmsCash = round((float) $pmsCashPayments
                    ->filter(fn (Payment $payment) => $shift->opened_at
                        && $payment->created_at->gte($shift->opened_at)
                        && $shift->closed_at
                        && $payment->created_at->lte($shift->closed_at))
                    ->sum('amount_base'), 2);

                return [
                    'id' => $shift->id,
                    'user' => $shift->user?->name,
                    'opened_at' => $shift->opened_at?->format('d/m H:i'),
                    'closed_at' => $shift->closed_at?->format('d/m H:i'),
                    'opening_float' => $opening,
                    'cash_sales' => $cash,
                    'card_sales' => $card,
                    'room_charge_sales' => $roomCharge,
                    'total_sales' => $total,
                    'expected_cash' => $expected,
                    'counted_cash' => $counted,
                    'over_short' => $overShort,
                    'pms_cash' => $pmsCash,
                    // A counted amount 10× (or more) the expectation is far
                    // likelier a keyed decimal slip (26.30 → 2630) than money.
                    'suspect_entry' => $expected > 0 && $counted >= $expected * 10,
                    'is_consistent' => abs($total - round($cash + $card + $roomCharge, 2)) < 0.01
                        && abs($expected - round($opening + $cash, 2)) < 0.01
                        && abs($overShort - round($counted - $expected, 2)) < 0.01,
                ];
            });

        return [
            'shifts' => $shifts->all(),
            'totals' => [
                'cash' => round((float) $shifts->sum('cash_sales'), 2),
                'card' => round((float) $shifts->sum('card_sales'), 2),
                'room_charge' => round((float) $shifts->sum('room_charge_sales'), 2),
                'total' => round((float) $shifts->sum('total_sales'), 2),
                'over_short' => round((float) $shifts->sum('over_short'), 2),
                'pms_cash' => round((float) $shifts->sum('pms_cash'), 2),
                'suspect_count' => $shifts->where('suspect_entry', true)->count(),
                'inconsistent_count' => $shifts->where('is_consistent', false)->count(),
            ],
        ];
    }
}
