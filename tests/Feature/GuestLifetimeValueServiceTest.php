<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\GuestLifetimeValueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestLifetimeValueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_realized_stays_for_ltv_and_separates_future_value(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $returning = Guest::create(['first_name' => 'Ana', 'last_name' => 'Repeat']);
        $oneTime = Guest::create(['first_name' => 'Ben', 'last_name' => 'Once']);

        $first = $this->reservation($returning, $room, $user, '2026-01-01', '2026-01-03', 'checked_out', 200, 20);
        $second = $this->reservation($returning, $room, $user, '2026-06-01', '2026-06-04', 'checked_out', 300, 30);
        $this->reservation($returning, $room, $user, '2026-08-01', '2026-08-03', 'confirmed', 250);
        $this->reservation($oneTime, $room, $user, '2026-05-01', '2026-05-02', 'checked_out', 100);
        $this->reservation($oneTime, $room, $user, '2026-09-01', '2026-09-02', 'cancelled', 900);
        FolioItem::create(['reservation_id' => $first->id, 'description' => 'Spa', 'type' => 'service', 'amount' => 50, 'charge_date' => '2026-01-02']);
        FolioItem::create(['reservation_id' => $first->id, 'description' => 'Offer', 'type' => 'discount', 'amount' => 10, 'charge_date' => '2026-01-02']);
        $pos = PosOrder::create(['reservation_id' => $second->id, 'status' => 'completed', 'total_amount' => 20, 'created_by' => $user->id]);
        PosOrderPayment::create(['pos_order_id' => $pos->id, 'direction' => 'in', 'method' => 'card', 'amount' => 20, 'paid_at' => now(), 'created_by' => $user->id]);

        $report = app(GuestLifetimeValueService::class)->summary();
        $ana = collect($report['guests'])->firstWhere('id', $returning->id);

        $this->assertSame(2, $report['summary']['total_guests']);
        $this->assertSame(1, $report['summary']['repeat_guests']);
        $this->assertSame(50.0, $report['summary']['repeat_rate']);
        $this->assertSame(610.0, $report['summary']['net_lifetime_value']);
        $this->assertSame(250.0, $report['summary']['upcoming_value']);
        $this->assertSame(510.0, $ana['net_value']);
        $this->assertSame(60.0, $ana['ancillary_value']);
        $this->assertSame(255.0, $ana['average_stay_value']);
        $this->assertSame(1, $ana['upcoming_stays']);
        $this->assertSame('returning', $ana['segment']);
        $this->assertSame(1, collect($report['segments'])->firstWhere('key', 'one_time')['guests']);
        CarbonImmutable::setTestNow();
    }

    private function reservation(Guest $guest, Room $room, User $user, string $checkIn, string $checkOut, string $status, float $total, float $commission = 0): Reservation
    {
        return Reservation::create([
            'guest_id' => $guest->id, 'room_id' => $room->id, 'created_by' => $user->id,
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'status' => $status,
            'total_amount' => $total, 'commission_amount' => $commission, 'adults' => 2, 'channel' => 'direct',
        ]);
    }
}
