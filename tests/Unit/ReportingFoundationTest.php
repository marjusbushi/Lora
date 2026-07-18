<?php

namespace Tests\Unit;

use App\Services\Reporting\KpiCalculator;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\SellableInventoryCalculator;
use App\Services\Reporting\StayRevenueAllocator;
use PHPUnit\Framework\TestCase;

class ReportingFoundationTest extends TestCase
{
    public function test_period_builds_previous_ranges_with_the_same_length(): void
    {
        $period = new ReportingPeriod('2026-07-01', '2026-07-31');

        $this->assertSame(31, $period->days());
        $this->assertSame(['from' => '2026-05-31', 'to' => '2026-06-30'], $period->previousPeriod()->toArray());
        $this->assertSame(['from' => '2025-07-01', 'to' => '2025-07-31'], $period->previousYear()->toArray());
    }

    public function test_stay_revenue_is_allocated_by_night_and_clipped_to_period(): void
    {
        $period = new ReportingPeriod('2026-07-01', '2026-07-02');
        $allocation = (new StayRevenueAllocator)->allocate('2026-06-30', '2026-07-03', 300, $period);

        $this->assertSame(['2026-07-01' => 100.0, '2026-07-02' => 100.0], $allocation);
        $this->assertSame(200.0, array_sum($allocation));
    }

    public function test_allocator_preserves_cents_across_the_full_stay(): void
    {
        $period = new ReportingPeriod('2026-07-01', '2026-07-03');
        $allocation = (new StayRevenueAllocator)->allocate('2026-07-01', '2026-07-04', 100, $period);

        $this->assertSame(100.0, array_sum($allocation));
        $this->assertSame([33.34, 33.33, 33.33], array_values($allocation));
    }

    public function test_sellable_inventory_deduplicates_blocked_rooms_per_day(): void
    {
        $period = new ReportingPeriod('2026-07-01', '2026-07-03');
        $result = (new SellableInventoryCalculator)->calculate(10, [
            ['room_id' => 1, 'starts_at' => '2026-07-01 10:00:00', 'ends_at' => '2026-07-03 09:00:00'],
            ['room_id' => 1, 'starts_at' => '2026-07-02 12:00:00', 'ends_at' => '2026-07-02 18:00:00'],
            ['room_id' => 2, 'starts_at' => '2026-07-02 00:00:01', 'ends_at' => '2026-07-03 23:00:00'],
        ], $period);

        $this->assertSame(9, $result['by_date']['2026-07-01']['sellable']);
        $this->assertSame(8, $result['by_date']['2026-07-02']['sellable']);
        $this->assertSame(8, $result['by_date']['2026-07-03']['sellable']);
        $this->assertSame(25, $result['sellable_room_nights']);
    }

    public function test_kpis_use_one_consistent_denominator(): void
    {
        $kpis = (new KpiCalculator)->calculate(800, 1000, 8, 10);

        $this->assertSame(80.0, $kpis['occupancy']);
        $this->assertSame(100.0, $kpis['adr']);
        $this->assertSame(80.0, $kpis['revpar']);
        $this->assertSame(100.0, $kpis['trevpar']);
    }
}
