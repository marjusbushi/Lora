<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\CancellationRiskService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancellationRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_cancellations_no_shows_and_overdue_arrivals(): void
    {
        $this->travelTo('2026-07-18 12:00:00');
        $user = User::factory()->create();
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);

        $this->reservation($user, $guest, $type, '101', 'direct', 'confirmed', '2026-07-10', 100);
        $this->reservation($user, $guest, $type, '102', 'booking.com', 'cancelled', '2026-07-10', 200);
        $this->reservation($user, $guest, $type, '103', 'booking.com', 'cancelled', '2026-07-11', 150, true);
        $this->reservation($user, $guest, $type, '104', 'expedia', 'checked_out', '2026-07-11', 300);
        $this->reservation($user, $guest, $type, '105', 'airbnb', 'pending', '2026-07-12', 120);
        $this->reservation($user, $guest, $type, '106', 'direct', 'cancelled', '2026-07-08', 100);
        $this->reservation($user, $guest, $type, '107', 'direct', 'checked_out', '2026-07-09', 100);

        $analytics = app(CancellationRiskService::class)
            ->withComparisons(new ReportingPeriod('2026-07-10', '2026-07-12'));
        $current = $analytics['current'];
        $booking = collect($current['channels'])->firstWhere('channel', 'booking.com');

        $this->assertSame(5, $current['summary']['total_count']);
        $this->assertSame(1, $current['summary']['cancelled_count']);
        $this->assertSame(20.0, $current['summary']['cancellation_rate']);
        $this->assertSame(1, $current['summary']['no_show_count']);
        $this->assertSame(20.0, $current['summary']['no_show_rate']);
        $this->assertSame(350.0, $current['summary']['lost_value']);
        $this->assertSame(2, $current['summary']['at_risk_count']);
        $this->assertSame(220.0, $current['summary']['at_risk_value']);
        $this->assertSame(2, $booking['bookings']);
        $this->assertSame(1, $booking['cancelled']);
        $this->assertSame(1, $booking['no_shows']);
        $this->assertSame(50.0, $booking['cancellation_rate']);
        $this->assertSame(50.0, $booking['no_show_rate']);
        $this->assertSame(350.0, $booking['lost_value']);
        $this->assertCount(2, $current['losses']);
        $this->assertSame(200.0, collect($current['daily'])->firstWhere('date', '2026-07-10')['cancelled_value']);
        $this->assertSame(150.0, collect($current['daily'])->firstWhere('date', '2026-07-11')['no_show_value']);
        $this->assertSame(-30.0, $analytics['changes']['cancellation_rate']);
        $this->assertSame(20.0, $analytics['changes']['no_show_rate']);
        $this->assertSame(250.0, $analytics['changes']['lost_value']);
        $this->assertNull($analytics['changes']['at_risk_count']);

        $future = $this->reservation($user, $guest, $type, '108', 'booking.com', 'confirmed', '2026-08-01', 240);
        Payment::create([
            'reservation_id' => $future->id,
            'amount' => 240,
            'method' => 'card',
            'created_by' => $user->id,
            'type' => 'refund',
        ]);
        $futureAnalytics = app(CancellationRiskService::class)
            ->summary(new ReportingPeriod('2026-08-01', '2026-08-01'));
        $risk = collect($futureAnalytics['at_risk'])->firstWhere('id', $future->id);

        $this->assertNotNull($risk);
        $this->assertSame(50, $risk['risk_score']);
        $this->assertSame('high', $risk['risk_level']);
        $this->assertSame(['unpaid', 'high_risk_channel'], $risk['risk_drivers']);
        $this->assertSame('secure_payment', $risk['recommended_action']);
        $this->assertSame(480.0, $risk['balance']);
        $this->assertSame(1, $futureAnalytics['risk_levels']['high']);
    }

    private function reservation(
        User $user,
        Guest $guest,
        RoomType $type,
        string $roomNumber,
        string $channel,
        string $status,
        string $checkIn,
        float $value,
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
            'check_out_date' => date('Y-m-d', strtotime($checkIn.' +2 days')),
            'status' => $status,
            'total_amount' => $value,
            'adults' => 1,
            'channel' => $channel,
        ]);
        if ($noShow) {
            $reservation->forceFill(['no_show_at' => $checkIn.' 18:00:00'])->saveQuietly();
        }

        return $reservation;
    }
}
