<?php

namespace App\Services\Reporting;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Services\BaseCurrency;

/**
 * What actually moved through the BANK accounts in a period — the report a
 * bookkeeper reconciles against the bank statement. Card POS tenders (per
 * currency), reservation card payments, deposits, withdrawals and transfers
 * all appear as the ledger rows that touched a bank account.
 */
class BankPaymentsReportService
{
    /**
     * @return array{accounts: array, rows: array, totals: array}
     */
    public function summary(ReportingPeriod $period, ?int $accountId = null): array
    {
        $banks = FinanceAccount::query()
            ->where('type', 'bank')
            ->orderBy('name')
            ->get();

        if ($accountId !== null) {
            $banks = $banks->where('id', $accountId)->values();
        }

        $bankIds = $banks->pluck('id')->all();

        $payments = FinancePayment::query()
            ->with(['account:id,name,currency,type', 'counterAccount:id,name,currency,type', 'createdBy:id,name'])
            ->whereBetween('paid_at', [$period->from, $period->to->endOfDay()])
            ->where(fn ($q) => $q
                ->whereIn('account_id', $bankIds)
                ->orWhere(fn ($qq) => $qq->where('direction', 'transfer')->whereIn('counter_account_id', $bankIds)))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $base = BaseCurrency::code();

        // Per-account running totals in the ACCOUNT's own currency — the same
        // column convention FinanceAccount::balance() uses.
        $summaries = $banks->keyBy('id')->map(fn (FinanceAccount $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'currency' => $account->currency,
            'iban' => $account->iban,
            'in' => 0.0,
            'out' => 0.0,
            'net' => 0.0,
        ])->all();

        $totals = ['in_base' => 0.0, 'out_base' => 0.0, 'net_base' => 0.0];
        $inBankView = fn (?int $id) => $id !== null && in_array($id, $bankIds, true);

        $apply = function (int $bankAccountId, FinancePayment $payment, bool $incoming) use (&$summaries, &$totals, $base) {
            $account = $summaries[$bankAccountId];
            $own = strtoupper((string) $account['currency']) === $base
                ? (float) $payment->amount_base
                : (float) $payment->amount;

            $key = $incoming ? 'in' : 'out';
            $summaries[$bankAccountId][$key] = round($account[$key] + $own, 2);
            $summaries[$bankAccountId]['net'] = round($summaries[$bankAccountId]['in'] - $summaries[$bankAccountId]['out'], 2);

            $baseAmount = (float) $payment->amount_base;
            $totals[$incoming ? 'in_base' : 'out_base'] = round($totals[$incoming ? 'in_base' : 'out_base'] + $baseAmount, 2);
            $totals['net_base'] = round($totals['in_base'] - $totals['out_base'], 2);
        };

        $rows = $payments->map(function (FinancePayment $payment) use ($apply, $inBankView) {
            $incoming = null;

            if ($payment->direction === 'in' && $inBankView($payment->account_id)) {
                $apply($payment->account_id, $payment, true);
                $incoming = true;
            } elseif ($payment->direction === 'out' && $inBankView($payment->account_id)) {
                $apply($payment->account_id, $payment, false);
                $incoming = false;
            } elseif ($payment->direction === 'transfer') {
                // A transfer can touch two banks: it leaves one and enters the
                // other. The ROW's sign follows the entering side when a bank
                // receives, otherwise the leaving side.
                if ($inBankView($payment->account_id)) {
                    $apply($payment->account_id, $payment, false);
                    $incoming = false;
                }
                if ($inBankView($payment->counter_account_id)) {
                    $apply($payment->counter_account_id, $payment, true);
                    $incoming = true;
                }
            }

            return [
                'id' => $payment->id,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'account' => $payment->account?->name,
                'counter_account' => $payment->counterAccount?->name,
                'direction' => $payment->direction,
                'incoming' => $incoming,
                'movement' => $payment->movement,
                'method' => $payment->method,
                'source' => $payment->source,
                'description' => $payment->description,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'amount_base' => (float) $payment->amount_base,
                'created_by' => $payment->createdBy?->name,
            ];
        })->values()->all();

        return [
            'accounts' => array_values($summaries),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }
}
