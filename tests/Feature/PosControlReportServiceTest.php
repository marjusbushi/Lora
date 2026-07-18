<?php

namespace Tests\Feature;

use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\User;
use App\Services\Reporting\PosControlReportService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosControlReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_payment_mix_refunds_voids_and_operators(): void
    {
        $user = User::factory()->create(['name' => 'Arka Test']);
        $sale = PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 100,
            'business_date' => '2026-07-10', 'paid_at' => '2026-07-10 12:00:00', 'created_by' => $user->id,
        ]);
        PosOrderPayment::create([
            'pos_order_id' => $sale->id, 'direction' => 'in', 'method' => 'cash',
            'amount' => 100, 'paid_at' => '2026-07-10 12:00:00', 'created_by' => $user->id,
        ]);
        PosOrderPayment::create([
            'pos_order_id' => $sale->id, 'direction' => 'out', 'method' => 'cash',
            'amount' => 20, 'paid_at' => '2026-07-10 13:00:00', 'created_by' => $user->id,
        ]);
        PosOrder::create([
            'status' => 'cancelled', 'payment_method' => 'card', 'total_amount' => 50,
            'cancelled_at' => '2026-07-10 14:00:00', 'cancelled_by' => $user->id,
            'created_by' => $user->id,
        ]);
        PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 10,
            'business_date' => '2026-07-10', 'paid_at' => '2026-07-10 10:00:00',
            'refunded_at' => '2026-07-10 11:00:00', 'refunded_by' => $user->id,
            'created_by' => $user->id,
        ]);

        $report = app(PosControlReportService::class)
            ->withComparison(new ReportingPeriod('2026-07-10', '2026-07-10'));
        $current = $report['current'];

        $this->assertSame(110.0, $current['summary']['gross_collected']);
        $this->assertSame(30.0, $current['summary']['refund_total']);
        $this->assertSame(80.0, $current['summary']['net_collected']);
        $this->assertSame(1, $current['summary']['void_count']);
        $this->assertSame(50.0, $current['summary']['void_value']);
        $this->assertSame(3, $current['summary']['missing_reason_count']);
        $this->assertSame(100.0, $current['summary']['exception_rate']);
        $this->assertSame(80.0, $current['methods'][0]['net']);
        $this->assertSame('Arka Test', $current['operators'][0]['operator']);
        $this->assertSame(2, $current['operators'][0]['refunds']);
        $this->assertCount(2, $current['refunds']);
        $this->assertCount(1, $current['voids']);
        $this->assertNull($report['changes']['exception_rate']);
    }

    public function test_refund_only_orders_are_included_in_the_exception_population(): void
    {
        $user = User::factory()->create();
        $sale = PosOrder::create([
            'status' => 'completed', 'payment_method' => 'card', 'total_amount' => 80,
            'business_date' => '2026-07-01', 'paid_at' => '2026-07-01 12:00:00',
            'created_by' => $user->id,
        ]);
        PosOrderPayment::create([
            'pos_order_id' => $sale->id, 'direction' => 'out', 'method' => 'card',
            'amount' => 80, 'paid_at' => '2026-07-10 12:00:00', 'created_by' => $user->id,
        ]);

        $current = app(PosControlReportService::class)
            ->summary(new ReportingPeriod('2026-07-10', '2026-07-10'));

        $this->assertSame(1, $current['summary']['order_population']);
        $this->assertSame(100.0, $current['summary']['exception_rate']);
    }
}
