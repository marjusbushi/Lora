<?php

namespace App\Services\Reporting;

use App\Models\Bill;
use App\Models\InventoryCategory;
use Illuminate\Support\Collection;

/**
 * "Ku shkojnë paratë?" — period purchases rolled up the category tree
 * (kategori → nënkategori → artikuj), line-accurate like the supplier
 * report: a mixed bill splits across branches, a line on a leaf credits
 * every ancestor exactly once, and lines that reach no category land in
 * one honest "Të tjera" row.
 */
final class PurchasesByCategoryReportService
{
    private const TOP_ITEMS_PER_NODE = 3;

    private const TOP_ITEMS_OVERALL = 10;

    public function __construct(private readonly KpiCalculator $kpiCalculator) {}

    /** @return array{current:array,previous_period:array,changes:array} */
    public function withComparison(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previous = $this->summary($period->previousPeriod());

        $previousSpend = collect($previous['categories'])->keyBy(fn (array $node) => $node['id'] ?? 'uncategorized');
        $current['categories'] = collect($current['categories'])->map(function (array $node) use ($previousSpend) {
            $before = $previousSpend->get($node['id'] ?? 'uncategorized');
            $node['previous_spend'] = $before['spend'] ?? 0.0;
            $node['change'] = $this->kpiCalculator->change($node['spend'], $node['previous_spend']);

            return $node;
        })->all();

        return [
            'current' => $current,
            'previous_period' => $previous,
            'changes' => [
                'total_spend' => $this->kpiCalculator->change($current['summary']['total_spend'], $previous['summary']['total_spend']),
                'bill_count' => $this->kpiCalculator->change($current['summary']['bill_count'], $previous['summary']['bill_count']),
                'uncategorized_spend' => $this->kpiCalculator->change($current['summary']['uncategorized_spend'], $previous['summary']['uncategorized_spend']),
            ],
        ];
    }

    public function summary(ReportingPeriod $period): array
    {
        $bills = Bill::query()
            ->whereDate('issue_date', '>=', $period->from->toDateString())
            ->whereDate('issue_date', '<=', $period->to->toDateString())
            ->with([
                'items:id,bill_id,inventory_item_id,description,quantity,unit,line_total',
                'items.item:id,name,sku,unit,type,category_id',
            ])
            ->get(['id', 'supplier_id', 'category', 'issue_date', 'currency', 'total', 'total_base']);

        $tree = InventoryCategory::flatTree();
        $ancestry = InventoryCategory::ancestryMap();
        $rootIdByName = collect($tree)->where('depth', 0)->pluck('id', 'name');

        $spend = [];
        $billsPerNode = [];
        $linesPerNode = [];
        $itemsPerNode = [];
        $uncategorized = ['spend' => 0.0, 'bills' => [], 'lines' => 0];
        $itemSpend = [];
        $lineCount = 0;

        $credit = function (array $path, float $amount, int $billId) use (&$spend, &$billsPerNode, &$linesPerNode): void {
            foreach ($path as $categoryId) {
                $spend[$categoryId] = ($spend[$categoryId] ?? 0.0) + $amount;
                $billsPerNode[$categoryId][$billId] = true;
                $linesPerNode[$categoryId] = ($linesPerNode[$categoryId] ?? 0) + 1;
            }
        };

        foreach ($bills as $bill) {
            $ratio = (float) $bill->total > 0 ? (float) $bill->total_base / (float) $bill->total : 0.0;

            if ($bill->items->isEmpty()) {
                $rootId = $bill->category ? $rootIdByName->get($bill->category) : null;
                if ($rootId !== null) {
                    $credit([$rootId], (float) $bill->total_base, $bill->id);
                } else {
                    $uncategorized['spend'] += (float) $bill->total_base;
                    $uncategorized['bills'][$bill->id] = true;
                }

                continue;
            }

            foreach ($bill->items as $line) {
                $lineCount++;
                $amount = round((float) $line->line_total * $ratio, 2);
                $path = $ancestry[$line->item?->category_id] ?? null;

                if ($path === null) {
                    $uncategorized['spend'] += $amount;
                    $uncategorized['bills'][$bill->id] = true;
                    $uncategorized['lines']++;
                } else {
                    $credit($path, $amount, $bill->id);
                }

                $itemKey = $line->inventory_item_id ?? 'free-'.$line->description;
                $entry = $itemSpend[$itemKey] ??= [
                    'id' => $line->inventory_item_id,
                    'name' => $line->item?->name ?? $line->description,
                    'sku' => $line->item?->sku,
                    'unit' => $line->unit ?: $line->item?->unit,
                    'quantity' => 0.0,
                    'spend' => 0.0,
                    'path' => $path ?? [],
                ];
                $entry['quantity'] += (float) $line->quantity;
                $entry['spend'] += $amount;
                $itemSpend[$itemKey] = $entry;
            }
        }

        foreach ($itemSpend as $entry) {
            foreach ($entry['path'] as $categoryId) {
                $itemsPerNode[$categoryId][] = $entry;
            }
        }

        $totalSpend = round((float) $bills->sum('total_base'), 2);
        $nodes = collect($tree)
            ->filter(fn (array $node) => ($spend[$node['id']] ?? 0.0) > 0.005)
            ->map(function (array $node) use ($spend, $billsPerNode, $linesPerNode, $itemsPerNode, $totalSpend) {
                $topItems = collect($itemsPerNode[$node['id']] ?? [])
                    ->sortByDesc('spend')
                    ->values();

                return [
                    'id' => $node['id'],
                    'category' => $node['name'],
                    'parent_id' => $node['parent_id'],
                    'depth' => $node['depth'],
                    'spend' => round($spend[$node['id']], 2),
                    'bill_count' => count($billsPerNode[$node['id']] ?? []),
                    'line_count' => $linesPerNode[$node['id']] ?? 0,
                    'share' => $totalSpend > 0 ? round($spend[$node['id']] / $totalSpend * 100, 1) : 0.0,
                    'top_items' => $topItems->take(self::TOP_ITEMS_PER_NODE)
                        ->map(fn (array $item) => $this->itemRow($item))
                        ->all(),
                    'top_items_truncated' => max(0, $topItems->count() - self::TOP_ITEMS_PER_NODE),
                ];
            })
            ->values();

        if ($uncategorized['spend'] > 0.005) {
            $nodes->push([
                'id' => null,
                'category' => Bill::UNCATEGORIZED,
                'parent_id' => null,
                'depth' => 0,
                'spend' => round($uncategorized['spend'], 2),
                'bill_count' => count($uncategorized['bills']),
                'line_count' => $uncategorized['lines'],
                'share' => $totalSpend > 0 ? round($uncategorized['spend'] / $totalSpend * 100, 1) : 0.0,
                'top_items' => [],
                'top_items_truncated' => 0,
            ]);
        }

        return [
            'period' => $period->toArray(),
            'summary' => [
                'total_spend' => $totalSpend,
                'bill_count' => $bills->count(),
                'line_count' => $lineCount,
                'uncategorized_spend' => round($uncategorized['spend'], 2),
            ],
            'categories' => $nodes->all(),
            'top_items' => collect($itemSpend)
                ->sortByDesc('spend')
                ->take(self::TOP_ITEMS_OVERALL)
                ->map(fn (array $item) => $this->itemRow($item))
                ->values()
                ->all(),
        ];
    }

    private function itemRow(array $item): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'sku' => $item['sku'],
            'unit' => $item['unit'],
            'quantity' => round($item['quantity'], 4),
            'spend' => round($item['spend'], 2),
        ];
    }
}
