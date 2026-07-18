<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task #189 (Copa 1a): pricing:snapshot records on-the-books occupancy per
 * future stay date × room type, idempotently — the raw pickup-pace history.
 */
class PricingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(Room $room, string $in, string $out, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => 'X'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'status' => $status,
            'total_amount' => 100,
            'adults' => 2,
            'channel' => 'direct',
        ]);
    }

    public function test_snapshot_counts_booked_per_night_with_checkout_day_free(): void
    {
        $type = RoomType::create(['name' => 'Twin', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        $r1 = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $r2 = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'available']);
        Room::create(['room_type_id' => $type->id, 'room_number' => '103', 'floor' => 1, 'status' => 'maintenance']);

        $d1 = now()->addDays(2)->toDateString();
        $d2 = now()->addDays(3)->toDateString();
        $d3 = now()->addDays(4)->toDateString(); // checkout day of both — must be free

        $this->reservation($r1, $d1, $d3);           // nights d1, d2
        $this->reservation($r2, $d2, $d3);           // night d2
        $this->reservation($r2, $d1, $d2, 'cancelled'); // cancelled — never counts

        $this->artisan('pricing:snapshot', ['--days' => 7])->assertSuccessful();

        $today = now()->toDateString();
        $row = fn (string $stay) => RoomInventorySnapshot::whereDate('snapshot_date', $today)
            ->whereDate('stay_date', $stay)->where('room_type_id', $type->id)->first();

        $this->assertSame(1, $row($d1)->booked, 'night 1: only r1');
        $this->assertSame(2, $row($d2)->booked, 'night 2: r1 + r2');
        $this->assertSame(0, $row($d3)->booked, 'checkout day is free');
        $this->assertSame('50.00', $row($d1)->booked_revenue, 'night 1 revenue: half of r1');
        $this->assertSame('150.00', $row($d2)->booked_revenue, 'night 2 revenue: r1 + r2');
        $this->assertSame('0.00', $row($d3)->booked_revenue, 'checkout day has no revenue');
        $this->assertSame(3, $row($d1)->total_rooms);
        $this->assertSame(1, $row($d1)->out_of_order, 'maintenance room counted out of order');
        $this->assertSame(1, $row($d1)->available, '3 total - 1 ooo - 1 booked');
    }

    public function test_rerun_updates_in_place_without_duplicates(): void
    {
        $type = RoomType::create(['name' => 'Dbl', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);

        $this->artisan('pricing:snapshot', ['--days' => 5])->assertSuccessful();
        $count = RoomInventorySnapshot::count();
        $this->assertSame(5, $count);

        // A booking lands, the command re-runs the same night → same rows, new counts.
        $in = now()->addDay()->toDateString();
        $out = now()->addDays(2)->toDateString();
        $this->reservation($room, $in, $out);

        $this->artisan('pricing:snapshot', ['--days' => 5])->assertSuccessful();
        $this->assertSame($count, RoomInventorySnapshot::count(), 'no duplicate rows on rerun');
        $this->assertSame(1, RoomInventorySnapshot::whereDate('stay_date', $in)
            ->where('room_type_id', $type->id)->first()->booked);
    }

    public function test_command_is_scheduled_nightly(): void
    {
        // The withSchedule callback fires on Artisan boot, so inspect via schedule:list.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('pricing:snapshot')
            ->assertSuccessful();
    }
}
