<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\BookingBehaviorService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingBehaviorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_lead_time_and_length_of_stay_distributions_with_comparisons(): void
    {
        $user = User::factory()->create();
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);

        $this->reservation($user, $guest, $type, '101', 'direct', '2026-07-10', '2026-07-11', '2026-07-10');
        $this->reservation($user, $guest, $type, '102', 'booking.com', '2026-07-10', '2026-07-13', '2026-06-30');
        $this->reservation($user, $guest, $type, '103', 'expedia', '2026-07-12', '2026-07-20', '2026-05-10');
        $this->reservation($user, $guest, $type, '104', 'direct', '2026-07-08', '2026-07-10', '2026-07-07');
        $this->reservation($user, $guest, $type, '105', 'agoda', '2026-07-11', '2026-07-13', '2026-07-01', 'cancelled');
        $this->reservation($user, $guest, $type, '106', 'airbnb', '2026-07-11', '2026-07-13', '2026-07-01', 'confirmed', true);

        $analytics = app(BookingBehaviorService::class)
            ->withComparisons(new ReportingPeriod('2026-07-10', '2026-07-12'));
        $current = $analytics['current'];

        $this->assertSame(3, $current['summary']['count']);
        $this->assertSame(24.3, $current['summary']['avg_lead']);
        $this->assertSame(10.0, $current['summary']['median_lead']);
        $this->assertSame(4.0, $current['summary']['avg_los']);
        $this->assertSame(3.0, $current['summary']['median_los']);
        $this->assertSame(33.3, $current['summary']['same_day_share']);
        $this->assertSame(33.3, $current['summary']['long_stay_share']);
        $this->assertSame(1, collect($current['lead_buckets'])->firstWhere('key', 'same_day')['count']);
        $this->assertSame(1, collect($current['lead_buckets'])->firstWhere('key', 'sixty_one_plus')['count']);
        $this->assertSame(1, collect($current['los_buckets'])->firstWhere('key', 'eight_plus')['count']);
        $this->assertSame(33.3, collect($current['channels'])->firstWhere('channel', 'direct')['share']);
        $this->assertSame(200.0, $analytics['changes']['count']);
        $this->assertSame(23.3, $analytics['changes']['avg_lead']);
        $this->assertSame(2.0, $analytics['changes']['avg_los']);
        $this->assertSame(33.3, $analytics['changes']['same_day_share']);

        $this->reservation($user, $guest, $type, '107', 'direct', '2026-07-15', '2026-07-15', '2026-07-14');
        $dayUse = app(BookingBehaviorService::class)->summary(new ReportingPeriod('2026-07-15', '2026-07-15'));

        $this->assertSame(1, collect($dayUse['los_buckets'])->firstWhere('key', 'zero_nights')['count']);
        $this->assertSame(0, collect($dayUse['los_buckets'])->firstWhere('key', 'one_night')['count']);
        $this->assertSame(0.0, $dayUse['summary']['avg_los']);
    }

    private function reservation(
        User $user,
        Guest $guest,
        RoomType $type,
        string $roomNumber,
        string $channel,
        string $checkIn,
        string $checkOut,
        string $createdAt,
        string $status = 'confirmed',
        bool $noShow = false,
    ): Reservation {
        $room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => $roomNumber,
            'floor' => 1,
            'status' => 'available',
        ]);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'status' => $status,
            'total_amount' => 100,
            'adults' => 1,
            'channel' => $channel,
        ]);
        $reservation->forceFill([
            'created_at' => $createdAt.' 10:00:00',
            'updated_at' => $createdAt.' 10:00:00',
            'no_show_at' => $noShow ? $checkIn.' 18:00:00' : null,
        ])->saveQuietly();

        return $reservation;
    }
}
