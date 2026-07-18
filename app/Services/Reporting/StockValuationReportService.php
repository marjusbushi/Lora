<?php

namespace App\Services\Reporting;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

final class StockValuationReportService
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
                'stock_value' => $this->kpiCalculator->change($current['summary']['stock_value'], $previous['summary']['stock_value']),
                'received_value' => $this->kpiCalculator->change($current['summary']['received_value'], $previous['summary']['received_value']),
                'consumed_value' => $this->kpiCalculator->change($current['summary']['consumed_value'], $previous['summary']['consumed_value']),
                'at_risk_count' => $this->kpiCalculator->change($current['summary']['at_risk_count'], $previous['summary']['at_risk_count']),
            ],
        ];
    }

    public function summary(ReportingPeriod $period): array
    {
        $from = $period->from->startOfDay();
        $end = $period->to->endOfDay();
        $movements = InventoryMovement::query()
            ->where('occurred_at', '<=', $end)
            ->select(['inventory_item_id', 'warehouse_id', 'type'])
            ->selectRaw('SUM(quantity) as ending_quantity')
            ->selectRaw('SUM(quantity * unit_cost) as ending_value')
            ->selectRaw('SUM(CASE WHEN occurred_at < ? THEN quantity ELSE 0 END) as opening_quantity', [$from])
            ->selectRaw('SUM(CASE WHEN occurred_at < ? THEN quantity * unit_cost ELSE 0 END) as opening_value', [$from])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? THEN quantity ELSE 0 END) as period_quantity', [$from])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? THEN quantity * unit_cost ELSE 0 END) as period_value', [$from])
            ->groupBy('inventory_item_id', 'warehouse_id', 'type')
            ->get();

        $movementsByItem = $movements->groupBy('inventory_item_id');
        $items = InventoryItem::query()
            ->where('type', '!=', 'service')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category', 'type', 'unit', 'average_cost', 'minimum_stock', 'is_active']);

        $rows = $items->map(fn (InventoryItem $item) => $this->itemRow(
            $item,
            collect($movementsByItem->get($item->id, [])),
            $period->days(),
        ));

        $warehouses = Warehouse::query()->pluck('name', 'id');
        $warehouseRows = $movements->groupBy('warehouse_id')->map(function (Collection $rows, $warehouseId) use ($warehouses) {
            $items = $rows->groupBy('inventory_item_id')->map(function (Collection $itemRows) {
                $quantity = (float) $itemRows->sum('ending_quantity');
                $ledgerValue = (float) $itemRows->sum('ending_value');

                return $quantity > 0.00005 ? max(0, $ledgerValue) : 0.0;
            });

            return [
                'id' => (int) $warehouseId,
                'name' => $warehouses->get($warehouseId, '—'),
                'stock_value' => round((float) $items->sum(), 2),
                'item_count' => $rows->groupBy('inventory_item_id')
                    ->filter(fn (Collection $itemRows) => (float) $itemRows->sum('ending_quantity') > 0.00005)->count(),
            ];
        })->sortByDesc('stock_value')->values();

        $stockValue = round((float) $rows->sum('ending_value'), 2);
        $openingValue = round((float) $rows->sum('opening_value'), 2);
        $atRisk = $rows->whereIn('status', ['negative', 'out', 'low']);

        return [
            'period' => $period->toArray(),
            'summary' => [
                'stock_value' => $stockValue,
                'opening_value' => $openingValue,
                'stock_change' => round($stockValue - $openingValue, 2),
                'received_value' => round((float) $rows->sum('received_value'), 2),
                'consumed_value' => round((float) $rows->sum('consumed_value'), 2),
                'transfer_value' => round((float) $rows->sum('transfer_value'), 2),
                'total_items' => $rows->count(),
                'at_risk_count' => $atRisk->count(),
                'negative_stock_count' => $rows->where('status', 'negative')->count(),
            ],
            'items' => $rows->sortByDesc(fn (array $row) => match ($row['status']) {
                'negative' => 4, 'out' => 3, 'low' => 2, default => 1,
            })->values()->all(),
            'warehouses' => $warehouseRows->all(),
            'top_consumption' => $rows->filter(fn (array $row) => $row['consumed_value'] > 0)
                ->sortByDesc('consumed_value')->take(8)->values()->all(),
        ];
    }

    private function itemRow(InventoryItem $item, Collection $movements, int $days): array
    {
        $openingQuantity = round((float) $movements->sum('opening_quantity'), 4);
        $endingQuantity = round((float) $movements->sum('ending_quantity'), 4);
        $openingLedgerValue = (float) $movements->sum('opening_value');
        $endingLedgerValue = (float) $movements->sum('ending_value');
        $fallbackCost = (float) $item->average_cost;
        $openingUnitCost = $openingQuantity > 0.00005 ? max(0, $openingLedgerValue / $openingQuantity) : $fallbackCost;
        $endingUnitCost = $endingQuantity > 0.00005 ? max(0, $endingLedgerValue / $endingQuantity) : $fallbackCost;

        $received = $movements->filter(fn ($row) => ! in_array($row->type, ['transfer_in', 'sale_release', 'sale_return'], true)
            && (float) $row->period_quantity > 0);
        $consumption = $movements->whereIn('type', ['sale', 'room_charge', 'sale_release', 'sale_return']);
        $consumedQuantity = max(0, -(float) $consumption->sum('period_quantity'));
        $consumedValue = max(0, -(float) $consumption->sum('period_value'));
        $dailyConsumption = $days > 0 ? $consumedQuantity / $days : 0.0;
        $daysCover = $dailyConsumption > 0.00005 ? round(max(0, $endingQuantity) / $dailyConsumption, 1) : null;
        $minimum = (float) $item->minimum_stock;
        $status = match (true) {
            $endingQuantity < -0.00005 => 'negative',
            $endingQuantity <= 0.00005 => 'out',
            $minimum > 0 && $endingQuantity <= $minimum => 'low',
            default => 'healthy',
        };

        return [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'category' => $item->category ?: $item->type,
            'unit' => $item->unit,
            'is_active' => $item->is_active,
            'opening_quantity' => $openingQuantity,
            'received_quantity' => round((float) $received->sum('period_quantity'), 4),
            'consumed_quantity' => round($consumedQuantity, 4),
            'ending_quantity' => $endingQuantity,
            'minimum_stock' => round($minimum, 4),
            'unit_cost' => round($endingUnitCost, 4),
            'opening_value' => round(max(0, $openingQuantity) * $openingUnitCost, 2),
            'ending_value' => round(max(0, $endingQuantity) * $endingUnitCost, 2),
            'received_value' => round(max(0, (float) $received->sum('period_value')), 2),
            'consumed_value' => round($consumedValue, 2),
            'transfer_value' => round(abs((float) $movements->where('type', 'transfer_out')->sum('period_value')), 2),
            'days_cover' => $daysCover,
            'status' => $status,
        ];
    }
}
