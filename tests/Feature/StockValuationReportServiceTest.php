<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\StockValuationReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockValuationReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_values_stock_and_separates_receipts_consumption_and_transfers(): void
    {
        $user = User::factory()->create();
        $central = Warehouse::create(['name' => 'Qendrore', 'type' => 'central', 'is_default' => true, 'is_active' => true]);
        $bar = Warehouse::create(['name' => 'Bar', 'type' => 'bar', 'is_active' => true]);
        $coffee = InventoryItem::create([
            'name' => 'Kafe', 'sku' => 'KAFE', 'type' => 'ingredient', 'unit' => 'kg',
            'average_cost' => 2, 'minimum_stock' => 5, 'is_active' => true,
        ]);
        InventoryItem::create([
            'name' => 'Çaj', 'sku' => 'CAJ', 'type' => 'ingredient', 'unit' => 'piece',
            'average_cost' => 1, 'minimum_stock' => 2, 'is_active' => true,
        ]);

        foreach ([
            [$central, 'opening_balance', 10, 2, '2026-07-01 09:00:00'],
            [$central, 'purchase', 5, 3, '2026-07-10 09:00:00'],
            [$central, 'sale', -8, 2.3333, '2026-07-11 09:00:00'],
            [$central, 'transfer_out', -2, 2, '2026-07-12 09:00:00'],
            [$bar, 'transfer_in', 2, 2, '2026-07-12 09:00:00'],
            [$central, 'purchase', 100, 3, '2026-07-20 09:00:00'],
        ] as [$warehouse, $type, $quantity, $unitCost, $occurredAt]) {
            InventoryMovement::create([
                'inventory_item_id' => $coffee->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'occurred_at' => $occurredAt,
                'created_by' => $user->id,
            ]);
        }

        $current = app(StockValuationReportService::class)
            ->summary(new ReportingPeriod('2026-07-10', '2026-07-15'));

        $this->assertSame(16.33, $current['summary']['stock_value']);
        $this->assertSame(20.0, $current['summary']['opening_value']);
        $this->assertSame(-3.67, $current['summary']['stock_change']);
        $this->assertSame(15.0, $current['summary']['received_value']);
        $this->assertSame(18.67, $current['summary']['consumed_value']);
        $this->assertSame(4.0, $current['summary']['transfer_value']);
        $this->assertSame(1, $current['summary']['at_risk_count']);
        $this->assertSame(2, $current['summary']['total_items']);
        $this->assertSame(7.0, $current['items'][1]['ending_quantity']);
        $this->assertSame('healthy', $current['items'][1]['status']);
        $this->assertSame('out', $current['items'][0]['status']);
        $this->assertSame(16.33, round(collect($current['warehouses'])->sum('stock_value'), 2));
        $this->assertSame('Kafe', $current['top_consumption'][0]['name']);
    }
}
