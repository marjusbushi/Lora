<?php

namespace App\Services\Reporting;

use App\Models\Bill;
use App\Models\InventoryCategory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SupplierPerformanceReportService
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
                'total_spend' => $this->kpiCalculator->change($current['summary']['total_spend'], $previous['summary']['total_spend']),
                'average_bill' => $this->kpiCalculator->change($current['summary']['average_bill'], $previous['summary']['average_bill']),
                'on_time_rate' => round($current['summary']['on_time_rate'] - $previous['summary']['on_time_rate'], 1),
                'overdue_exposure' => $this->kpiCalculator->change($current['summary']['overdue_exposure'], $previous['summary']['overdue_exposure']),
            ],
        ];
    }

    public function summary(ReportingPeriod $period): array
    {
        $from = $period->from->startOfDay();
        $end = $period->to->endOfDay();
        $bills = Bill::query()
            ->whereDate('issue_date', '<=', $end->toDateString())
            ->with([
                'supplier:id,name,category,payment_terms_days,is_active',
                'payments:id,bill_id,direction,amount_base,paid_at',
                'items:id,bill_id,inventory_item_id,description,quantity,unit,unit_cost,line_total,received_at',
                'items.item:id,name,sku,unit,type,category_id',
            ])
            ->get(['id', 'supplier_id', 'number', 'category', 'issue_date', 'due_date', 'currency', 'total', 'total_base']);

        $currentBills = $bills->filter(fn (Bill $bill) => $bill->issue_date->betweenIncluded($from, $end));
        $supplierRows = $bills->groupBy('supplier_id')
            ->map(fn (Collection $supplierBills) => $this->supplierRow($supplierBills, $from, $end))
            ->filter(fn (array $row) => $row['spend'] > 0 || $row['outstanding'] > 0)
            ->sortByDesc('spend')
            ->values();

        $totalSpend = round((float) $currentBills->sum('total_base'), 2);
        $supplierRows = $supplierRows->map(function (array $row) use ($totalSpend) {
            $row['spend_share'] = $totalSpend > 0 ? round($row['spend'] / $totalSpend * 100, 1) : 0.0;
            $row['status'] = match (true) {
                $row['overdue'] > 0 || $row['spend_share'] >= 50 => 'risk',
                $row['on_time_rate'] < 80 || $row['receipt_rate'] < 80 => 'watch',
                default => 'healthy',
            };

            return $row;
        });

        $eligible = $currentBills->filter(fn (Bill $bill) => $this->isPaymentEligible($bill, $end));
        $onTime = $eligible->filter(fn (Bill $bill) => $this->isPaidOnTime($bill, $end))->count();
        $averageBill = $currentBills->isNotEmpty() ? $totalSpend / $currentBills->count() : 0.0;

        return [
            'period' => $period->toArray(),
            'summary' => [
                'total_spend' => $totalSpend,
                'average_bill' => round($averageBill, 2),
                'bill_count' => $currentBills->count(),
                'supplier_count' => $supplierRows->count(),
                'on_time_rate' => $eligible->isNotEmpty() ? round($onTime / $eligible->count() * 100, 1) : 100.0,
                'overdue_exposure' => round((float) $supplierRows->sum('overdue'), 2),
                'outstanding' => round((float) $supplierRows->sum('outstanding'), 2),
                'top_supplier_share' => (float) ($supplierRows->max('spend_share') ?? 0),
            ],
            'suppliers' => $supplierRows->all(),
            'categories' => $this->lineAccurateCategories($currentBills),
            'top_items' => $this->topItems($currentBills),
        ];
    }

    /**
     * Spend per ROOT inventory-tree category, read from the LINES — a mixed
     * bill splits across categories instead of following one hand-picked
     * document label. Totals-only bills fall back to their stored category.
     *
     * @return list<array{category: string, spend: float, bill_count: int}>
     */
    private function lineAccurateCategories(Collection $currentBills): array
    {
        $rootNames = InventoryCategory::rootNameMap();
        $spend = [];
        $billsPerCategory = [];

        foreach ($currentBills as $bill) {
            $ratio = (float) $bill->total > 0 ? (float) $bill->total_base / (float) $bill->total : 0.0;
            $touched = [];

            if ($bill->items->isEmpty()) {
                $name = $bill->category ?: Bill::UNCATEGORIZED;
                $spend[$name] = ($spend[$name] ?? 0.0) + (float) $bill->total_base;
                $touched[$name] = true;
            } else {
                foreach ($bill->items as $line) {
                    $name = $rootNames[$line->item?->category_id] ?? Bill::UNCATEGORIZED;
                    $spend[$name] = ($spend[$name] ?? 0.0) + round((float) $line->line_total * $ratio, 2);
                    $touched[$name] = true;
                }
            }

            foreach (array_keys($touched) as $name) {
                $billsPerCategory[$name] = ($billsPerCategory[$name] ?? 0) + 1;
            }
        }

        return collect($spend)
            ->map(fn (float $amount, string $name) => [
                'category' => $name,
                'spend' => round($amount, 2),
                'bill_count' => $billsPerCategory[$name] ?? 0,
            ])
            ->sortByDesc('spend')
            ->values()
            ->all();
    }

    private function supplierRow(Collection $bills, CarbonInterface $from, CarbonInterface $end): array
    {
        $current = $bills->filter(fn (Bill $bill) => $bill->issue_date->betweenIncluded($from, $end));
        $supplier = $bills->first()?->supplier;
        $outstanding = $bills->sum(fn (Bill $bill) => $this->remainingAt($bill, $end));
        $overdue = $bills->filter(fn (Bill $bill) => $bill->due_date?->lt($end->startOfDay()))
            ->sum(fn (Bill $bill) => $this->remainingAt($bill, $end));
        $eligible = $current->filter(fn (Bill $bill) => $this->isPaymentEligible($bill, $end));
        $settled = $current->filter(fn (Bill $bill) => $this->remainingAt($bill, $end) <= 0.005);
        $paymentDays = $settled->map(function (Bill $bill) use ($end) {
            $lastPayment = $bill->payments->where('direction', 'out')->where('paid_at', '<=', $end)->max('paid_at');

            return $lastPayment ? max(0, $bill->issue_date->diffInDays(Carbon::parse($lastPayment))) : 0;
        });
        $stockItems = $current->flatMap->items
            ->filter(fn ($item) => $item->inventory_item_id && $item->item?->type !== 'service');
        $received = $stockItems->filter(fn ($item) => $item->received_at && $item->received_at->lte($end))->count();

        return [
            'id' => $supplier?->id,
            'name' => $supplier?->name ?? '—',
            'category' => $supplier?->category ?: '—',
            'payment_terms_days' => (int) ($supplier?->payment_terms_days ?? 0),
            'is_active' => (bool) ($supplier?->is_active ?? false),
            'bill_count' => $current->count(),
            'spend' => round((float) $current->sum('total_base'), 2),
            'average_bill' => $current->isNotEmpty() ? round((float) $current->avg('total_base'), 2) : 0.0,
            'outstanding' => round((float) $outstanding, 2),
            'overdue' => round((float) $overdue, 2),
            'on_time_rate' => $eligible->isNotEmpty()
                ? round($eligible->filter(fn (Bill $bill) => $this->isPaidOnTime($bill, $end))->count() / $eligible->count() * 100, 1)
                : 100.0,
            'average_payment_days' => $paymentDays->isNotEmpty() ? round((float) $paymentDays->avg(), 1) : null,
            'receipt_rate' => $stockItems->isNotEmpty() ? round($received / $stockItems->count() * 100, 1) : 100.0,
            'item_count' => $stockItems->pluck('inventory_item_id')->unique()->count(),
        ];
    }

    private function remainingAt(Bill $bill, CarbonInterface $end): float
    {
        $paid = (float) $bill->payments
            ->where('direction', 'out')
            ->filter(fn ($payment) => $payment->paid_at->lte($end))
            ->sum('amount_base');

        return max(0, round((float) $bill->total_base - $paid, 2));
    }

    private function isPaymentEligible(Bill $bill, CarbonInterface $end): bool
    {
        return $bill->due_date
            && ($bill->due_date->lte($end) || $this->remainingAt($bill, $end) <= 0.005);
    }

    private function isPaidOnTime(Bill $bill, CarbonInterface $end): bool
    {
        if (! $bill->due_date || $this->remainingAt($bill, $end) > 0.005) {
            return false;
        }

        $lastPayment = $bill->payments->where('direction', 'out')->where('paid_at', '<=', $end)->max('paid_at');

        return $lastPayment && Carbon::parse($lastPayment)->startOfDay()->lte($bill->due_date);
    }

    private function topItems(Collection $bills): array
    {
        return $bills->flatMap(function (Bill $bill) {
            $baseRatio = (float) $bill->total > 0 ? (float) $bill->total_base / (float) $bill->total : 1.0;

            return $bill->items
                ->filter(fn ($item) => $item->inventory_item_id && $item->item?->type !== 'service')
                ->map(fn ($item) => [
                    'id' => $item->inventory_item_id,
                    'name' => $item->item?->name ?? $item->description,
                    'sku' => $item->item?->sku,
                    'unit' => $item->unit ?: $item->item?->unit,
                    'supplier_id' => $bill->supplier_id,
                    'quantity' => (float) $item->quantity,
                    'spend' => (float) $item->line_total * $baseRatio,
                ]);
        })->groupBy('id')->map(function (Collection $rows) {
            $quantity = (float) $rows->sum('quantity');
            $spend = (float) $rows->sum('spend');
            $first = $rows->first();

            return [
                'id' => $first['id'],
                'name' => $first['name'],
                'sku' => $first['sku'],
                'unit' => $first['unit'],
                'quantity' => round($quantity, 4),
                'supplier_count' => $rows->pluck('supplier_id')->unique()->count(),
                'average_unit_cost' => $quantity > 0 ? round($spend / $quantity, 4) : 0.0,
                'spend' => round($spend, 2),
            ];
        })->sortByDesc('spend')->take(10)->values()->all();
    }
}
