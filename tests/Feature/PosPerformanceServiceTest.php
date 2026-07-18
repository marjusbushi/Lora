<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\User;
use App\Services\Reporting\PosPerformanceService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_sales_hourly_demand_items_and_margin(): void
    {
        $user = User::factory()->create();
        $category = MenuCategory::create(['name' => 'Ushqim', 'sort_order' => 1]);
        $item = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Pasta',
            'price' => 60,
            'cost_price' => 20,
            'is_available' => true,
        ]);
        $bar = MenuCategory::create(['name' => 'Bar', 'sort_order' => 2]);
        $sameName = MenuItem::create([
            'menu_category_id' => $bar->id,
            'name' => 'Pasta',
            'price' => 30,
            'cost_price' => 10,
            'is_available' => true,
        ]);

        $this->order($user, $item, '2026-07-10', '2026-07-10 13:15:00', 120, 20, 100, 2);
        $this->order($user, $sameName, '2026-07-10', '2026-07-10 13:45:00', 30, 0, 30, 1);
        $this->order($user, $sameName, '2026-07-09', '2026-07-09 11:00:00', 10, 0, 10, 1);

        $report = app(PosPerformanceService::class)
            ->withComparison(new ReportingPeriod('2026-07-10', '2026-07-10'));
        $current = $report['current'];

        $this->assertSame(130.0, $current['summary']['total_revenue']);
        $this->assertSame(2, $current['summary']['order_count']);
        $this->assertSame(65.0, $current['summary']['avg_ticket']);
        $this->assertSame(50.0, $current['summary']['estimated_cost']);
        $this->assertSame(80.0, $current['summary']['gross_profit']);
        $this->assertSame(61.5, $current['summary']['gross_margin']);
        $this->assertSame(130.0, collect($current['hours'])->firstWhere('hour', 13)['revenue']);
        $this->assertSame(100.0, $current['categories'][0]['revenue']);
        $this->assertSame('Pasta', $current['top_items'][0]['name']);
        $this->assertCount(2, $current['top_items']);
        $this->assertSame(['Ushqim', 'Bar'], collect($current['top_items'])->pluck('category')->all());
        $this->assertSame(1200.0, $report['changes']['revenue']);
        $this->assertSame(61.5, $report['changes']['gross_margin']);
    }

    private function order(User $user, MenuItem $item, string $businessDate, string $paidAt, float $subtotal, float $discount, float $total, int $quantity): void
    {
        $order = PosOrder::create([
            'status' => 'completed',
            'payment_method' => 'cash',
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'business_date' => $businessDate,
            'paid_at' => $paidAt,
            'covers' => 2,
            'created_by' => $user->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'menu_item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => 60,
            'total_price' => $subtotal,
        ]);
    }
}
