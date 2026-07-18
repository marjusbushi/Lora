<?php

namespace App\Services\Reporting;

use App\Models\PosOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class PosPerformanceService
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
                'revenue' => $this->kpiCalculator->change($current['summary']['total_revenue'], $previous['summary']['total_revenue']),
                'orders' => $this->kpiCalculator->change($current['summary']['order_count'], $previous['summary']['order_count']),
                'avg_ticket' => $this->kpiCalculator->change($current['summary']['avg_ticket'], $previous['summary']['avg_ticket']),
                'gross_margin' => $previous['summary']['order_count'] > 0
                    ? round($current['summary']['gross_margin'] - $previous['summary']['gross_margin'], 1)
                    : null,
            ],
        ];
    }

    public function summary(ReportingPeriod $period): array
    {
        $orders = $this->range(PosOrder::query(), $period)
            ->where('status', 'completed')
            ->whereNull('refunded_at')
            ->with(['items.menuItem.category'])
            ->get();

        $categories = collect();
        $items = collect();
        $hours = collect(range(0, 23))->mapWithKeys(fn (int $hour) => [$hour => ['hour' => $hour, 'orders' => 0, 'revenue' => 0.0]]);
        $weekdays = collect(range(1, 7))->mapWithKeys(fn (int $day) => [$day => ['weekday' => $day, 'orders' => 0, 'revenue' => 0.0]]);
        $totalCost = 0.0;

        foreach ($orders as $order) {
            $revenue = (float) $order->total_amount;
            $when = CarbonImmutable::parse($order->paid_at ?? $order->created_at);
            $hour = $hours->get($when->hour);
            $hour['orders']++;
            $hour['revenue'] += $revenue;
            $hours->put($when->hour, $hour);
            $weekday = $weekdays->get($when->dayOfWeekIso);
            $weekday['orders']++;
            $weekday['revenue'] += $revenue;
            $weekdays->put($when->dayOfWeekIso, $weekday);

            $subtotal = (float) $order->subtotal_amount;
            $factor = $subtotal > 0 ? $revenue / $subtotal : 1.0;
            foreach ($order->items as $line) {
                $itemRevenue = (float) $line->total_price * $factor;
                $itemCost = (float) $line->quantity * (float) ($line->menuItem?->cost_price ?? 0);
                $category = $line->menuItem?->category?->name ?: 'Pa kategori';
                $item = $line->menuItem?->name ?: 'Artikull';
                $this->accumulate($categories, $category, $line->quantity, $itemRevenue, $itemCost);
                $this->accumulate($items, "{$category}\0{$item}", $line->quantity, $itemRevenue, $itemCost, $category, $item);
                $totalCost += $itemCost;
            }
        }

        $totalRevenue = round((float) $orders->sum('total_amount'), 2);
        $orderCount = $orders->count();
        $grossProfit = round($totalRevenue - $totalCost, 2);

        return [
            'period' => $period->toArray(),
            'summary' => [
                'total_revenue' => $totalRevenue,
                'order_count' => $orderCount,
                'avg_ticket' => $orderCount > 0 ? round($totalRevenue / $orderCount, 2) : 0.0,
                'covers' => (int) $orders->sum('covers'),
                'discounts' => round((float) $orders->sum('discount_amount'), 2),
                'estimated_cost' => round($totalCost, 2),
                'gross_profit' => $grossProfit,
                'gross_margin' => $totalRevenue > 0 ? round($grossProfit / $totalRevenue * 100, 1) : 0.0,
            ],
            'categories' => $categories->sortByDesc('revenue')->values()->all(),
            'top_items' => $items->sortByDesc('revenue')->take(15)->values()->all(),
            'hours' => $hours->map(fn (array $row) => [...$row, 'revenue' => round($row['revenue'], 2)])->values()->all(),
            'weekdays' => $weekdays->map(fn (array $row) => [...$row, 'revenue' => round($row['revenue'], 2)])->values()->all(),
        ];
    }

    private function range($query, ReportingPeriod $period)
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        return $query->where(function ($range) use ($from, $to) {
            $range->where(fn ($business) => $business->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to))
                ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereBetween('paid_at', ["{$from} 00:00:00", "{$to} 23:59:59"]))
                ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]));
        });
    }

    private function accumulate(Collection $rows, string $key, int $quantity, float $revenue, float $cost, ?string $category = null, ?string $name = null): void
    {
        $row = $rows->get($key, ['name' => $name ?? $key, 'category' => $category, 'qty' => 0, 'revenue' => 0.0, 'cost' => 0.0]);
        $row['qty'] += $quantity;
        $row['revenue'] = round($row['revenue'] + $revenue, 2);
        $row['cost'] = round($row['cost'] + $cost, 2);
        $row['gross_profit'] = round($row['revenue'] - $row['cost'], 2);
        $row['gross_margin'] = $row['revenue'] > 0 ? round($row['gross_profit'] / $row['revenue'] * 100, 1) : 0.0;
        $rows->put($key, $row);
    }
}
