<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\SupplierPerformanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPerformanceReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_historical_supplier_spend_payment_delivery_and_concentration(): void
    {
        $user = User::factory()->create();
        $account = FinanceAccount::create(['name' => 'Banka', 'type' => 'bank', 'currency' => 'EUR', 'is_active' => true]);
        $cash = FinanceAccount::create(['name' => 'Arka', 'type' => 'cash', 'currency' => 'EUR', 'is_active' => true]);
        $supplierA = Supplier::create(['name' => 'Furnitori A', 'category' => 'Ushqim', 'payment_terms_days' => 10, 'is_active' => true]);
        $supplierB = Supplier::create(['name' => 'Furnitori B', 'category' => 'Pije', 'payment_terms_days' => 20, 'is_active' => true]);
        $item = InventoryItem::create([
            'name' => 'Kafe', 'sku' => 'KAFE-SUP', 'type' => 'ingredient', 'unit' => 'kg',
            'average_cost' => 3, 'minimum_stock' => 2, 'is_active' => true,
        ]);
        $service = InventoryItem::create([
            'name' => 'Transport', 'sku' => 'TRANSPORT-SUP', 'type' => 'service', 'unit' => 'service',
            'average_cost' => 0, 'minimum_stock' => 0, 'is_active' => true,
        ]);

        $paidOnTime = $this->bill($supplierA, 'A-1', '2026-07-01', '2026-07-10', 100, 'Ushqim');
        $partialLate = $this->bill($supplierA, 'A-2', '2026-07-02', '2026-07-05', 50, 'Ushqim');
        $this->bill($supplierB, 'B-1', '2026-07-03', '2026-07-15', 50, 'Pije');

        $this->payment($paidOnTime, $account, $user, 100, '2026-07-08 10:00:00');
        $this->payment($partialLate, $account, $user, 20, '2026-07-04 10:00:00');
        FinancePayment::create([
            'direction' => 'transfer', 'account_id' => $account->id, 'counter_account_id' => $cash->id,
            'amount' => 500, 'currency' => 'EUR', 'method' => 'bank', 'source' => 'manual',
            'bill_id' => $partialLate->id, 'paid_at' => '2026-07-04 11:00:00', 'created_by' => $user->id,
        ]);

        BillItem::create([
            'bill_id' => $paidOnTime->id, 'inventory_item_id' => $item->id, 'description' => 'Kafe',
            'quantity' => 10, 'unit' => 'kg', 'unit_cost' => 3, 'line_total' => 30, 'received_at' => '2026-07-03 10:00:00',
        ]);
        BillItem::create([
            'bill_id' => $paidOnTime->id, 'inventory_item_id' => $service->id, 'description' => 'Transport',
            'quantity' => 1, 'unit' => 'service', 'unit_cost' => 10, 'line_total' => 10, 'received_at' => '2026-07-03 10:00:00',
        ]);
        BillItem::create([
            'bill_id' => $partialLate->id, 'inventory_item_id' => $item->id, 'description' => 'Kafe',
            'quantity' => 5, 'unit' => 'kg', 'unit_cost' => 4, 'line_total' => 20,
        ]);

        $result = app(SupplierPerformanceReportService::class)
            ->summary(new ReportingPeriod('2026-07-01', '2026-07-15'));

        $this->assertSame(200.0, $result['summary']['total_spend']);
        $this->assertSame(66.67, $result['summary']['average_bill']);
        $this->assertSame(3, $result['summary']['bill_count']);
        $this->assertSame(2, $result['summary']['supplier_count']);
        $this->assertSame(33.3, $result['summary']['on_time_rate']);
        $this->assertSame(30.0, $result['summary']['overdue_exposure']);
        $this->assertSame(80.0, $result['summary']['outstanding']);
        $this->assertSame(75.0, $result['summary']['top_supplier_share']);

        $first = $result['suppliers'][0];
        $this->assertSame('Furnitori A', $first['name']);
        $this->assertSame(150.0, $first['spend']);
        $this->assertSame(75.0, $first['spend_share']);
        $this->assertSame(30.0, $first['overdue']);
        $this->assertSame(50.0, $first['on_time_rate']);
        $this->assertSame(50.0, $first['receipt_rate']);
        $this->assertSame('risk', $first['status']);

        $this->assertSame('Kafe', $result['top_items'][0]['name']);
        $this->assertSame(15.0, $result['top_items'][0]['quantity']);
        $this->assertSame(50.0, $result['top_items'][0]['spend']);
        $this->assertSame(3.3333, $result['top_items'][0]['average_unit_cost']);
        $this->assertCount(1, $result['top_items']);

        // A payment made after the report end must not rewrite the historical overdue balance.
        $this->payment($partialLate, $account, $user, 30, '2026-07-18 10:00:00');
        $historical = app(SupplierPerformanceReportService::class)
            ->summary(new ReportingPeriod('2026-07-01', '2026-07-15'));
        $this->assertSame(30.0, $historical['summary']['overdue_exposure']);
    }

    public function test_supplier_billed_in_one_foreign_currency_exposes_the_original_amount(): void
    {
        // Base-first display (Renato, Saturn): the row leads with the base
        // figure; a supplier documented entirely in ONE non-base currency
        // also exposes the original sum for the sub-line.
        $foreignSupplier = Supplier::create(['name' => 'Booking.com B.V', 'category' => 'OTA', 'payment_terms_days' => 0, 'is_active' => true]);
        $mixedSupplier = Supplier::create(['name' => 'Mikse', 'category' => 'Ushqim', 'payment_terms_days' => 0, 'is_active' => true]);

        foreach ([['F-1', 100.0], ['F-2', 50.0]] as [$number, $total]) {
            $bill = $this->bill($foreignSupplier, $number, '2026-07-04', '2026-07-20', $total, 'OTA');
            // The Bill saving hook derives total_base = total / fx_rate.
            $bill->forceFill(['currency' => 'USD', 'fx_rate' => 0.5])->save();
        }

        // Mixed document currencies → no single original sum, no sub-line.
        $this->bill($mixedSupplier, 'M-1', '2026-07-05', '2026-07-20', 40, 'Ushqim');
        $usd = $this->bill($mixedSupplier, 'M-2', '2026-07-06', '2026-07-20', 10, 'Ushqim');
        $usd->forceFill(['currency' => 'USD', 'fx_rate' => 0.5])->save();

        $rows = collect(app(SupplierPerformanceReportService::class)
            ->summary(new ReportingPeriod('2026-07-01', '2026-07-15'))['suppliers'])->keyBy('name');

        $this->assertSame(['currency' => 'USD', 'total' => 150.0], $rows['Booking.com B.V']['foreign']);
        $this->assertSame(300.0, $rows['Booking.com B.V']['spend']);
        $this->assertNull($rows['Mikse']['foreign']);
    }

    private function bill(Supplier $supplier, string $number, string $issueDate, string $dueDate, float $total, string $category): Bill
    {
        return Bill::create([
            'supplier_id' => $supplier->id,
            'number' => $number,
            'category' => $category,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'currency' => 'EUR',
            'total' => $total,
            'status' => 'open',
        ]);
    }

    private function payment(Bill $bill, FinanceAccount $account, User $user, float $amount, string $paidAt): FinancePayment
    {
        return FinancePayment::create([
            'direction' => 'out',
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'method' => 'bank',
            'source' => 'manual',
            'bill_id' => $bill->id,
            'paid_at' => $paidAt,
            'created_by' => $user->id,
        ]);
    }
}
