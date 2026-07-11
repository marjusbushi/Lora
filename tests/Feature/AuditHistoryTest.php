<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\TestCase;

class AuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Room $room;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $this->room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);

        $this->actingAs($this->admin);
        $this->guest = Guest::create(['first_name' => 'Arben', 'last_name' => 'Hoxha']);
    }

    public function test_reservation_timeline_records_actor_source_ip_and_before_after_changes(): void
    {
        $this->post(route('reservations.store'), [
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in_date' => '2026-08-10',
            'check_out_date' => '2026-08-12',
            'status' => 'confirmed',
            'adults' => 2,
            'children' => 0,
            'channel' => 'direct',
            'total_amount' => 200,
        ])->assertSessionHasNoErrors();

        $reservation = Reservation::latest('id')->firstOrFail();
        $created = AuditLog::where('action', 'reservation.created')
            ->where('subject_id', $reservation->id)->sole();

        $this->assertSame($this->admin->id, $created->causer_id);
        $this->assertSame('staff', $created->source);
        $this->assertSame('127.0.0.1', $created->ip_address);
        $this->assertSame('confirmed', $created->properties['changes']['status']['to']);

        $this->put(route('reservations.update', $reservation), [
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in_date' => '2026-08-11',
            'check_out_date' => '2026-08-13',
            'status' => 'confirmed',
            'adults' => 2,
            'children' => 0,
            'channel' => 'direct',
            'total_amount' => 240,
        ])->assertSessionHasNoErrors();

        $updated = AuditLog::where('action', 'reservation.updated')
            ->where('subject_id', $reservation->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-08-10', $updated->properties['changes']['check_in_date']['from']);
        $this->assertSame('2026-08-11', $updated->properties['changes']['check_in_date']['to']);
        $this->assertSame('200.00', $updated->properties['changes']['total_amount']['from']);
        $this->assertSame('240.00', $updated->properties['changes']['total_amount']['to']);

        $this->post(route('reservations.check-in', $reservation))->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'reservation.check_in')
            ->where('subject_id', $reservation->id)->count());

        $this->get(route('reservations.show', $reservation))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Show')
                ->has('history', 3)
                ->where('history.0.action', 'reservation.check_in')
                ->where('history.0.actor', $this->admin->name)
                ->where('history.0.changes.0.label', 'Statusi'));
    }

    public function test_guest_timeline_combines_profile_and_reservation_history(): void
    {
        $reservation = Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-03',
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
        ]);

        $this->guest->update(['phone' => '+355691234567']);

        $this->get(route('guests.show', $this->guest))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Guests/Show')
                ->has('history', 3)
                ->where('history.0.action', 'guest.updated')
                ->where('history.1.action', 'reservation.created')
                ->where('history.1.subject.url', route('reservations.show', $reservation)));
    }

    public function test_global_history_is_admin_only_and_audit_rows_are_immutable(): void
    {
        $this->get(route('audit-logs.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('AuditLogs/Index')
                ->has('logs.data'));

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager)->get(route('audit-logs.index'))->assertForbidden();

        $log = AuditLog::latest('id')->firstOrFail();
        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_channel_manager_reservation_is_identified_as_channex(): void
    {
        auth()->logout();

        $reservation = Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => '2026-10-01',
            'check_out_date' => '2026-10-02',
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'booking.com',
        ]);

        $log = AuditLog::where('action', 'reservation.created')->where('subject_id', $reservation->id)->sole();
        $this->assertNull($log->causer_id);
        $this->assertSame('channex', $log->source);
    }
}
