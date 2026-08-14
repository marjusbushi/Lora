<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Reporting\PurchasesByCategoryReportService;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\StockValuationReportService;
use App\Services\Reporting\SupplierPerformanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTreeReportsTest extends TestCase
{
    use RefreshDatabase;

    private InventoryCategory $pije;

    private InventoryCategory $alkoolike;

    private InventoryCategory $vere;

    private InventoryCategory $ushqim;

    private InventoryItem $wineItem;

    private InventoryItem $breadItem;

    private InventoryItem $noCategoryItem;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // Pije → Alkoolike → Verë (the 3-level maximum) plus a second root.
        $this->pije = InventoryCategory::create(['name' => 'Pije']);
        $this->alkoolike = InventoryCategory::create(['name' => 'Alkoolike', 'parent_id' => $this->pije->id]);
        $this->vere = InventoryCategory::create(['name' => 'Verë', 'parent_id' => $this->alkoolike->id]);
        $this->ushqim = InventoryCategory::create(['name' => 'Ushqim']);

        $this->wineItem = $this->item('Verë e kuqe', 'WINE-1', $this->vere->id);
        $this->breadItem = $this->item('Bukë', 'BREAD-1', $this->ushqim->id);
        $this->noCategoryItem = $this->item('Pa kategori', 'MYSTERY-1', null);

        $this->supplier = Supplier::create(['name' => 'Furnitori', 'category' => 'Ushqim', 'payment_terms_days' => 10, 'is_active' => true]);
    }

    public function test_purchases_by_category_rolls_leaf_spend_to_every_ancestor_exactly_once(): void
    {
        $this->seedJulyBills();
        // Previous period: Verë spend 50 → +100% change on the whole branch.
        $juneBill = $this->bill('JUN-1', '2026-06-10', 50);
        $this->line($juneBill, $this->wineItem, 1, 50);

        $report = app(PurchasesByCategoryReportService::class)->withComparison(new ReportingPeriod('2026-07-01', '2026-07-31'));
        $current = $report['current'];
        $byName = collect($current['categories'])->keyBy('category');

        // The leaf's 100 appears at every level of its branch exactly once…
        $this->assertSame(100.0, $byName['Verë']['spend']);
        $this->assertSame(100.0, $byName['Alkoolike']['spend']);
        $this->assertSame(100.0, $byName['Pije']['spend']);
        // …and never leaks into the sibling root (lines 100 + doc-category 60).
        $this->assertSame(160.0, $byName['Ushqim']['spend']);
        $this->assertSame(30.0, $byName['Të tjera']['spend']);

        $this->assertSame(290.0, $current['summary']['total_spend']);
        $this->assertSame(4, $current['summary']['bill_count']);
        $this->assertSame(6, $current['summary']['line_count']);
        $this->assertSame(30.0, $current['summary']['uncategorized_spend']);
        $this->assertSame(34.5, $byName['Pije']['share']);
        $this->assertSame(55.2, $byName['Ushqim']['share']);

        // Tree metadata drives the drill-down.
        $this->assertSame(0, $byName['Pije']['depth']);
        $this->assertSame(1, $byName['Alkoolike']['depth']);
        $this->assertSame(2, $byName['Verë']['depth']);
        $this->assertSame($this->alkoolike->id, $byName['Verë']['parent_id']);
        $this->assertNull($byName['Të tjera']['id']);

        // Comparison: the wine branch doubled; Ushqim had no June spend.
        $this->assertSame(100.0, $byName['Verë']['change']);
        $this->assertSame(50.0, $byName['Verë']['previous_spend']);
        $this->assertNull($byName['Ushqim']['change']);

        // Top items: Ushqim node holds 4 items → 3 shown + 1 truncated.
        $this->assertCount(3, $byName['Ushqim']['top_items']);
        $this->assertSame(1, $byName['Ushqim']['top_items_truncated']);
        $this->assertSame('Bukë', $byName['Ushqim']['top_items'][0]['name']);
    }

    public function test_supplier_performance_categories_carry_the_same_tree(): void
    {
        $this->seedJulyBills();

        $report = app(SupplierPerformanceReportService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));
        $byName = collect($report['categories'])->keyBy('category');

        $this->assertSame(100.0, $byName['Verë']['spend']);
        $this->assertSame(100.0, $byName['Pije']['spend']);
        $this->assertSame(160.0, $byName['Ushqim']['spend']);
        $this->assertSame(30.0, $byName['Të tjera']['spend']);
        $this->assertSame(1, $byName['Pije']['bill_count']);
        $this->assertSame(3, $byName['Ushqim']['bill_count']);
        $this->assertSame(2, $byName['Verë']['depth']);
    }

    public function test_stock_valuation_rolls_values_up_the_tree_and_exposes_category_ids(): void
    {
        $warehouse = Warehouse::create(['name' => 'Magazina', 'is_active' => true]);
        $this->movement($this->wineItem, $warehouse, 'purchase', 10, 10, '2026-07-05 10:00:00');
        $this->movement($this->breadItem, $warehouse, 'purchase', 5, 4, '2026-07-06 10:00:00');
        $this->movement($this->breadItem, $warehouse, 'sale', -2, 4, '2026-07-10 10:00:00');
        $this->movement($this->noCategoryItem, $warehouse, 'purchase', 1, 30, '2026-07-07 10:00:00');

        $report = app(StockValuationReportService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));
        $byName = collect($report['categories'])->keyBy('category');

        foreach (['Verë', 'Alkoolike', 'Pije'] as $branch) {
            $this->assertSame(100.0, $byName[$branch]['stock_value'], $branch);
            $this->assertSame(100.0, $byName[$branch]['received_value'], $branch);
            $this->assertSame(0.0, $byName[$branch]['consumed_value'], $branch);
            $this->assertSame(1, $byName[$branch]['item_count'], $branch);
        }
        $this->assertSame(12.0, $byName['Ushqim']['stock_value']);
        $this->assertSame(20.0, $byName['Ushqim']['received_value']);
        $this->assertSame(8.0, $byName['Ushqim']['consumed_value']);
        $this->assertSame(30.0, $byName['Të tjera']['stock_value']);

        $wineRow = collect($report['items'])->firstWhere('sku', 'WINE-1');
        $this->assertSame($this->vere->id, $wineRow['category_id']);
    }

    public function test_empty_period_yields_no_category_rows(): void
    {
        $report = app(PurchasesByCategoryReportService::class)->withComparison(new ReportingPeriod('2026-03-01', '2026-03-31'));

        $this->assertSame([], $report['current']['categories']);
        $this->assertSame(0.0, $report['current']['summary']['total_spend']);
        $this->assertNull($report['changes']['total_spend']);
    }

    /**
     * July: mixed bill (Verë 100 + Bukë 40), totals-only bill with doc
     * category Ushqim (60), an uncategorized line (30), and three more
     * Ushqim lines (30+20+10) so the node holds 4 items for the top-item cap.
     */
    private function seedJulyBills(): void
    {
        $mixed = $this->bill('JUL-1', '2026-07-03', 140);
        $this->line($mixed, $this->wineItem, 2, 100);
        $this->line($mixed, $this->breadItem, 4, 40);

        $this->bill('JUL-2', '2026-07-05', 60, 'Ushqim');

        $mystery = $this->bill('JUL-3', '2026-07-08', 30);
        $this->line($mystery, $this->noCategoryItem, 1, 30);

        $extras = $this->bill('JUL-4', '2026-07-09', 60);
        foreach ([['Djathë', 'CHEESE-1', 30], ['Qumësht', 'MILK-1', 20], ['Vaj', 'OIL-1', 10]] as [$name, $sku, $total]) {
            $this->line($extras, $this->item($name, $sku, $this->ushqim->id), 1, $total);
        }
    }

    private function item(string $name, string $sku, ?int $categoryId): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name, 'sku' => $sku, 'type' => 'ingredient', 'unit' => 'copë',
            'category_id' => $categoryId, 'average_cost' => 1, 'minimum_stock' => 0, 'is_active' => true,
        ]);
    }

    private function bill(string $number, string $issueDate, float $total, ?string $category = null): Bill
    {
        // bills.category is NOT NULL; on line-based bills the lines win anyway.
        return Bill::create([
            'supplier_id' => $this->supplier->id, 'number' => $number, 'category' => $category ?? Bill::UNCATEGORIZED,
            'issue_date' => $issueDate, 'due_date' => '2026-08-15', 'currency' => 'EUR',
            'total' => $total, 'status' => 'open',
        ]);
    }

    private function line(Bill $bill, InventoryItem $item, float $quantity, float $total): BillItem
    {
        return BillItem::create([
            'bill_id' => $bill->id, 'inventory_item_id' => $item->id, 'description' => $item->name,
            'quantity' => $quantity, 'unit' => $item->unit, 'unit_cost' => $quantity > 0 ? $total / $quantity : 0,
            'line_total' => $total,
        ]);
    }

    private function movement(InventoryItem $item, Warehouse $warehouse, string $type, float $quantity, float $unitCost, string $occurredAt): InventoryMovement
    {
        return InventoryMovement::create([
            'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => $type,
            'quantity' => $quantity, 'unit_cost' => $unitCost, 'occurred_at' => $occurredAt,
        ]);
    }
}
