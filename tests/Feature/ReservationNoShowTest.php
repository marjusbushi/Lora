<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No-show (folds deferred #7536): status becomes 'cancelled' (room frees, ARI
 * follows via the observer) PLUS the no_show_at/no_show_by stamps that keep
 * no-shows separate from real cancellations in every report. The undo
 * re-validates availability — the room may have been resold meanwhile.
 */
class ReservationNoShowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-20 12:00:00');
        \Carbon\Carbon::setTestNow('2026-08-20 12:00:00');
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function room(string $number = '101'): Room
    {
        $type = RoomType::firstOrCreate(
            ['name' => 'Std'],
            ['base_price' => 80, 'max_occupancy' => 3, 'amenities' => []],
        );

        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'available']);
    }

    private function reservation(Room $room, string $in = '2026-08-18', string $out = '2026-08-21', string $status = 'confirmed'): Reservation
    {
        $guest = Guest::create(['first_name' => 'NoShow', 'last_name' => 'Guest '.$room->room_number.$in]);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'status' => $status,
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'booking.com',
        ]);
    }

    public function test_marking_stamps_cancels_and_audits(): void
    {
        $reservation = $this->reservation($this->room());

        $this->actingAs($this->admin)
            ->post(route('reservations.no-show', $reservation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $reservation->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->no_show_at);
        $this->assertSame($this->admin->id, (int) $fresh->no_show_by);

        $audit = AuditLog::where('action', 'reservation.no_show')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame($reservation->id, (int) $audit->subject_id);

        // The stamp is what separates it from a plain cancellation in reports.
        $this->assertSame(1, Reservation::whereNotNull('no_show_at')->count());
    }

    public function test_future_arrivals_and_wrong_statuses_are_refused(): void
    {
        $room = $this->room('201');
        $future = $this->reservation($room, '2026-08-25', '2026-08-28');
        $this->actingAs($this->admin)->post(route('reservations.no-show', $future))
            ->assertSessionHasErrors(['no_show']);

        // Arrival TODAY is still not a no-show — the date must have passed.
        $today = $this->reservation($this->room('202'), '2026-08-20', '2026-08-23');
        $this->actingAs($this->admin)->post(route('reservations.no-show', $today))
            ->assertSessionHasErrors(['no_show']);

        $checkedIn = $this->reservation($this->room('203'), '2026-08-18', '2026-08-22', 'checked_in');
        $this->actingAs($this->admin)->post(route('reservations.no-show', $checkedIn))
            ->assertSessionHasErrors(['no_show']);

        $cancelled = $this->reservation($this->room('204'), '2026-08-18', '2026-08-22', 'cancelled');
        $this->actingAs($this->admin)->post(route('reservations.no-show', $cancelled))
            ->assertSessionHasErrors(['no_show']);
    }

    public function test_pending_overdue_arrivals_qualify_too(): void
    {
        $reservation = $this->reservation($this->room('301'), '2026-08-19', '2026-08-22', 'pending');

        $this->actingAs($this->admin)->post(route('reservations.no-show', $reservation))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('cancelled', $reservation->fresh()->status);
    }

    public function test_undo_restores_when_the_room_is_still_free(): void
    {
        $reservation = $this->reservation($this->room('401'));
        $this->actingAs($this->admin)->post(route('reservations.no-show', $reservation));

        $this->actingAs($this->admin)->post(route('reservations.no-show.undo', $reservation))
            ->assertRedirect()->assertSessionHas('success');

        $fresh = $reservation->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertNull($fresh->no_show_at);
        $this->assertNull($fresh->no_show_by);
        $this->assertSame(1, AuditLog::where('action', 'reservation.no_show_undone')->count());
    }

    public function test_undo_refuses_when_the_room_was_resold(): void
    {
        $room = $this->room('501');
        $reservation = $this->reservation($room);
        $this->actingAs($this->admin)->post(route('reservations.no-show', $reservation));

        // Someone books the freed room for overlapping nights.
        $this->reservation($room, '2026-08-19', '2026-08-22');

        $this->actingAs($this->admin)->post(route('reservations.no-show.undo', $reservation))
            ->assertSessionHasErrors(['no_show']);

        $fresh = $reservation->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->no_show_at);
    }

    public function test_undo_refuses_a_plain_cancellation_without_the_stamp(): void
    {
        $plain = $this->reservation($this->room('601'), '2026-08-18', '2026-08-22', 'cancelled');

        $this->actingAs($this->admin)->post(route('reservations.no-show.undo', $plain))
            ->assertSessionHasErrors(['no_show']);
    }

    public function test_permission_boundary_holds(): void
    {
        $reservation = $this->reservation($this->room('701'));
        $housekeeper = User::factory()->create();
        $housekeeper->assignRole('housekeeping');

        $this->actingAs($housekeeper)->post(route('reservations.no-show', $reservation))
            ->assertForbidden();

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }
}
