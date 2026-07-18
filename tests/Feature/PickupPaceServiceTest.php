<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\PickupPaceService;
use App\Services\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupPaceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_real_pickup_against_historical_snapshots(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);

        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-23',
            'status' => 'confirmed',
            'total_amount' => 300,
            'adults' => 1,
            'children' => 0,
            'channel' => 'direct',
        ]);

        foreach (['2026-07-20', '2026-07-21', '2026-07-22'] as $stayDate) {
            RoomInventorySnapshot::create([
                'snapshot_date' => '2026-07-11',
                'stay_date' => $stayDate,
                'room_type_id' => $type->id,
                'total_rooms' => 1,
                'out_of_order' => 0,
                'booked' => 0,
                'booked_revenue' => 0,
                'available' => 1,
            ]);
            RoomInventorySnapshot::create([
                'snapshot_date' => '2026-07-17',
                'stay_date' => $stayDate,
                'room_type_id' => $type->id,
                'total_rooms' => 1,
                'out_of_order' => 0,
                'booked' => 1,
                'booked_revenue' => 100,
                'available' => 0,
            ]);
        }

        $summary = app(PickupPaceService::class)->summary(
            new ReportingPeriod('2026-07-20', '2026-07-22'),
            CarbonImmutable::parse('2026-07-18'),
        );
        $oneDay = collect($summary['horizons'])->firstWhere('days', 1);
        $sevenDays = collect($summary['horizons'])->firstWhere('days', 7);
        $this->assertSame(3, $summary['current']['nights']);
        $this->assertSame(300.0, $summary['current']['revenue']);
        $this->assertSame(0, $oneDay['pickup_nights']);
        $this->assertSame(0.0, $oneDay['pickup_revenue']);
        $this->assertSame(3, $sevenDays['pickup_nights']);
        $this->assertSame(300.0, $sevenDays['pickup_revenue']);
        $this->assertSame(7, $summary['baseline_days']);
        $this->assertSame(1, $summary['daily'][0]['pickup_nights']);
    }

    public function test_it_does_not_invent_revenue_for_legacy_snapshots(): void
    {
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);

        RoomInventorySnapshot::create([
            'snapshot_date' => '2026-07-11',
            'stay_date' => '2026-07-20',
            'room_type_id' => $type->id,
            'total_rooms' => 1,
            'out_of_order' => 0,
            'booked' => 1,
            'available' => 0,
        ]);

        $summary = app(PickupPaceService::class)->summary(
            new ReportingPeriod('2026-07-20', '2026-07-20'),
            CarbonImmutable::parse('2026-07-18'),
        );
        $sevenDays = collect($summary['horizons'])->firstWhere('days', 7);

        $this->assertTrue($sevenDays['available']);
        $this->assertFalse($sevenDays['revenue_available']);
        $this->assertNull($sevenDays['pickup_revenue']);
    }
}
