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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The materialization rate: of the nights the photo showed N days before
 * arrival, how many actually happened — applied to today's book as the
 * "expected to arrive" figure (Renato's honesty coefficient).
 */
class PickupPaceMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $type;

    private array $rooms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        foreach (['101', '102'] as $number) {
            $this->rooms[] = Room::create(['room_type_id' => $this->type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'available']);
        }
    }

    private function photo(string $snapshotDate, string $stayDate, int $booked): void
    {
        RoomInventorySnapshot::create([
            'snapshot_date' => $snapshotDate, 'stay_date' => $stayDate,
            'room_type_id' => $this->type->id, 'total_rooms' => 2, 'out_of_order' => 0,
            'booked' => $booked, 'booked_revenue' => $booked * 50, 'available' => max(0, 2 - $booked),
        ]);
    }

    private function realizedStay(Room $room, string $in, string $out): void
    {
        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => uniqid()])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $in, 'check_out_date' => $out,
            'status' => 'checked_out', 'total_amount' => 100, 'adults' => 2, 'channel' => 'direct',
        ]);
    }

    public function test_learns_materialization_and_projects_expected_arrivals(): void
    {
        // Three finished dates, each photographed exactly 7 days before with
        // 2 nights booked; reality delivered 2, 1 and 2 → 5/6 = 83.3%.
        foreach ([5, 4, 3] as $offset) {
            $stay = today()->subDays($offset)->toDateString();
            $this->photo(today()->subDays($offset + 7)->toDateString(), $stay, 2);
        }
        $this->realizedStay($this->rooms[0], today()->subDays(5)->toDateString(), today()->subDays(2)->toDateString()); // covers all 3 nights
        $this->realizedStay($this->rooms[1], today()->subDays(5)->toDateString(), today()->subDays(4)->toDateString()); // night -5
        $this->realizedStay($this->rooms[1], today()->subDays(3)->toDateString(), today()->subDays(2)->toDateString()); // night -3

        // A photo pair with 0 booked must carry NO signal (migration-hole guard).
        $this->photo(today()->subDays(13)->toDateString(), today()->subDays(6)->toDateString(), 0);
        $this->realizedStay($this->rooms[0], today()->subDays(7)->toDateString(), today()->subDays(5)->toDateString());

        // A COMPLETE reference photo 7 days ago for the whole future window,
        // so the baseline (and therefore 'expected') activates.
        $period = new ReportingPeriod(today()->toDateString(), today()->addDays(29)->toDateString());
        for ($offset = 0; $offset <= 29; $offset++) {
            $this->photo(today()->subDays(7)->toDateString(), today()->addDays($offset)->toDateString(), 0);
        }

        // Today's book: one 2-night future stay → current nights = 2.
        Reservation::create([
            'room_id' => $this->rooms[0]->id,
            'guest_id' => Guest::create(['first_name' => 'F', 'last_name' => 'Uture'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => today()->addDays(3)->toDateString(),
            'check_out_date' => today()->addDays(5)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 100, 'adults' => 2, 'channel' => 'direct',
        ]);

        $summary = app(PickupPaceService::class)->summary($period);
        $horizon7 = collect($summary['horizons'])->firstWhere('days', 7);

        $this->assertSame(83.3, $horizon7['materialization_pct']);
        $this->assertSame(3, $horizon7['materialization_sample'], 'the zero-booked pair is not a learning day');
        $this->assertSame(2, $horizon7['expected_nights'], 'round(2 nights × 83.3%)');
        $this->assertNotNull($summary['expected']);
        $this->assertSame(7, $summary['expected']['days']);
        $this->assertSame(83.3, $summary['expected']['pct']);
        $this->assertSame(2, $summary['expected']['nights']);
    }

    public function test_without_history_everything_stays_null(): void
    {
        $summary = app(PickupPaceService::class)
            ->summary(new ReportingPeriod(today()->toDateString(), today()->addDays(6)->toDateString()));

        $this->assertNull($summary['expected']);
        foreach ($summary['horizons'] as $horizon) {
            $this->assertNull($horizon['materialization_pct']);
            $this->assertNull($horizon['expected_nights']);
        }
    }
}
