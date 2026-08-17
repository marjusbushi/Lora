<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationSplitProposal;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SplitStayProposalTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $type;

    private User $user;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->type = RoomType::create(['name' => 'Studio', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $this->user = User::factory()->create();
        $this->guest = Guest::create(['first_name' => 'Mysafir', 'last_name' => 'Test']);
    }

    private function room(string $number): Room
    {
        return Room::create(['room_type_id' => $this->type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'available']);
    }

    private function stay(Room $room, int $inDays, int $outDays, array $attrs = []): Reservation
    {
        return Reservation::create(array_merge([
            'room_id' => $room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->user->id,
            'check_in_date' => today()->addDays($inDays)->toDateString(),
            'check_out_date' => today()->addDays($outDays)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 2,
        ], $attrs));
    }

    public function test_proposal_round_trip_and_cascade(): void
    {
        $room = $this->room('A1');
        $reservation = $this->stay($room, 1, 6);

        $proposal = ReservationSplitProposal::create([
            'reservation_id' => $reservation->id,
            'segments' => [
                ['room_id' => $room->id, 'check_in' => today()->addDay()->toDateString(), 'check_out' => today()->addDays(4)->toDateString()],
                ['room_id' => $room->id, 'check_in' => today()->addDays(4)->toDateString(), 'check_out' => today()->addDays(6)->toDateString()],
            ],
        ]);

        $fresh = ReservationSplitProposal::sole();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->outcome);
        $this->assertCount(2, $fresh->segments);
        $this->assertSame($room->id, $fresh->segments[0]['room_id']);
        $this->assertSame($reservation->id, $fresh->reservation->id);
        $this->assertSame($proposal->id, $reservation->pendingSplitProposal->id);

        // Deleting the reservation must never leave an orphan proposal.
        $reservation->forceDelete();
        $this->assertSame(0, ReservationSplitProposal::count());
    }

    /** The anchor scenario: proposal to split [d+1, d+8) as B[1..4) then A[4..8). */
    private function anchoredProposal(): array
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 0, 4, ['status' => 'checked_in']);
        $this->stay($roomB, 4, 9, ['status' => 'checked_in']);
        $reservation = $this->stay($roomB, 1, 8, [
            'channel' => 'booking.com', 'channel_ref' => 'BK900', 'total_amount' => 700,
            'notes' => 'BookingCom #BK900 — PROPOZIM NDARJEJE (fol me mysafirin)',
        ]);
        $proposal = ReservationSplitProposal::create([
            'reservation_id' => $reservation->id,
            'segments' => [
                ['room_id' => $roomB->id, 'check_in' => today()->addDays(1)->toDateString(), 'check_out' => today()->addDays(4)->toDateString()],
                ['room_id' => $roomA->id, 'check_in' => today()->addDays(4)->toDateString(), 'check_out' => today()->addDays(8)->toDateString()],
            ],
        ]);

        return [$admin, $roomA, $roomB, $reservation, $proposal];
    }

    public function test_accepting_splits_the_stay_into_linked_prorated_rows(): void
    {
        [$admin, $roomA, $roomB, $reservation, $proposal] = $this->anchoredProposal();

        $this->actingAs($admin)->post(route('reservations.split-proposal.accept', $reservation))
            ->assertRedirect()->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame($roomB->id, $reservation->room_id);
        $this->assertSame(today()->addDays(4)->toDateString(), $reservation->check_out_date->toDateString());
        $this->assertSame(300.0, (float) $reservation->total_amount); // 3 of 7 nights
        $this->assertNotNull($reservation->booking_group_id);
        $this->assertStringNotContainsString('PROPOZIM NDARJEJE', $reservation->notes);
        $this->assertStringContainsString('pjesa 1/2', $reservation->notes);

        $second = Reservation::where('channel_ref', 'BK900')->whereKeyNot($reservation->id)->sole();
        $this->assertSame($roomA->id, $second->room_id);
        $this->assertSame(today()->addDays(4)->toDateString(), $second->check_in_date->toDateString());
        $this->assertSame(today()->addDays(8)->toDateString(), $second->check_out_date->toDateString());
        $this->assertSame(400.0, (float) $second->total_amount); // 4 of 7 nights (remainder)
        $this->assertSame($reservation->booking_group_id, $second->booking_group_id);
        $this->assertSame($reservation->booked_at?->toDateTimeString(), $second->booked_at?->toDateTimeString());
        $this->assertStringContainsString('pjesa 2/2', $second->notes);

        $proposal->refresh();
        $this->assertSame('accepted', $proposal->status);
        $this->assertSame('accepted', $proposal->outcome);
        $this->assertSame($admin->id, $proposal->decided_by);
        $this->assertNotNull($proposal->decided_at);
    }

    public function test_accepting_a_stale_plan_rolls_everything_back(): void
    {
        [$admin, $roomA, , $reservation] = $this->anchoredProposal();
        // Segment 2's room got taken meanwhile — the plan is stale.
        $this->stay($roomA, 5, 7);

        $this->actingAs($admin)->post(route('reservations.split-proposal.accept', $reservation))
            ->assertSessionHasErrors(['proposal']);

        $this->assertSame(today()->addDays(8)->toDateString(), $reservation->refresh()->check_out_date->toDateString());
        $this->assertSame(1, Reservation::where('channel_ref', 'BK900')->count()); // nothing half-split
        $this->assertSame('pending', ReservationSplitProposal::sole()->status);
    }

    public function test_declining_records_the_outcome_and_touches_nothing_else(): void
    {
        [$admin, , $roomB, $reservation, $proposal] = $this->anchoredProposal();

        $this->actingAs($admin)->post(route('reservations.split-proposal.decline', $reservation), [
            'outcome' => 'declined_escalated',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame($roomB->id, $reservation->room_id); // untouched
        $this->assertSame(today()->addDays(8)->toDateString(), $reservation->check_out_date->toDateString());
        $this->assertSame(700.0, (float) $reservation->total_amount);
        $this->assertSame('confirmed', $reservation->status); // Lora NEVER cancels
        $this->assertStringNotContainsString('PROPOZIM NDARJEJE', $reservation->notes); // stale marker cleared
        $this->assertSame(1, Reservation::where('channel_ref', 'BK900')->count());

        $proposal->refresh();
        $this->assertSame('declined', $proposal->status);
        $this->assertSame('declined_escalated', $proposal->outcome);
        $this->assertSame($admin->id, $proposal->decided_by);
    }

    public function test_decline_rejects_an_unknown_outcome(): void
    {
        [$admin, , , $reservation] = $this->anchoredProposal();

        $this->actingAs($admin)->post(route('reservations.split-proposal.decline', $reservation), [
            'outcome' => 'cancelled_by_system',
        ])->assertSessionHasErrors(['outcome']);

        $this->assertSame('pending', ReservationSplitProposal::sole()->status);
    }

    public function test_accepted_split_freezes_channel_redeliveries_for_the_desk(): void
    {
        [$admin, , , $reservation] = $this->anchoredProposal();
        $this->actingAs($admin)->post(route('reservations.split-proposal.accept', $reservation))->assertSessionHasNoErrors();

        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
            'services.channex.webhook_secret' => 'topsecret',
        ]);
        \App\Models\ChannelMapping::create([
            'channel' => 'channex', 'room_type_id' => $this->type->id,
            'channex_property_id' => 'PROP-1', 'channex_room_type_id' => 'RT-1', 'channex_rate_plan_id' => 'RP-1',
        ]);
        $summary = app(\App\Services\ChannexBookingImporter::class)->importRevision([
            'id' => 'REV-9',
            'attributes' => [
                'ota_name' => 'Booking.com', 'ota_reservation_code' => 'BK900', 'status' => 'modified',
                'currency' => 'EUR', 'amount' => '700.00',
                'customer' => ['name' => 'Mysafir', 'surname' => 'Test'],
                'rooms' => [[
                    'room_type_id' => 'RT-1',
                    'checkin_date' => today()->addDays(1)->toDateString(),
                    'checkout_date' => today()->addDays(8)->toDateString(),
                    'amount' => '700.00', 'occupancy' => ['adults' => 2],
                ]],
            ],
        ]);

        $this->assertNotEmpty($summary['flagged']); // left for the desk
        $this->assertSame(2, Reservation::where('channel_ref', 'BK900')->count()); // split intact
        $this->assertSame(today()->addDays(4)->toDateString(), $reservation->refresh()->check_out_date->toDateString());
        $this->assertTrue(\App\Models\ChannelSyncLog::where('action', 'booking.modified_after_split')->exists());
    }

    public function test_pending_count_is_shared_on_every_page_and_tracks_decisions(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $room = $this->room('A1');
        $reservation = $this->stay($room, 1, 6);
        $proposal = ReservationSplitProposal::create([
            'reservation_id' => $reservation->id,
            'segments' => [['room_id' => $room->id, 'check_in' => today()->addDay()->toDateString(), 'check_out' => today()->addDays(6)->toDateString()]],
        ]);

        $this->actingAs($admin)->get(route('reservations.calendar'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('splitProposalsPending', 1));

        // A decision must invalidate the cached count — the banner disappears.
        $proposal->update(['status' => ReservationSplitProposal::STATUS_ACCEPTED]);

        $this->actingAs($admin)->get(route('reservations.calendar'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('splitProposalsPending', 0));
    }
}
