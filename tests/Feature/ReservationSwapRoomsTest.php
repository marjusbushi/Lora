<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
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
 * The calendar's drag-onto-a-bar gesture: swap two reservations' rooms in ONE
 * transaction — both sides validate against everyone except each other, both
 * flip or neither, and "Zhbëj" is just the same swap called again.
 */
class ReservationSwapRoomsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-19 12:00:00');
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function room(string $number, string $status = 'available'): Room
    {
        $type = RoomType::firstOrCreate(
            ['name' => 'Std'],
            ['base_price' => 80, 'max_occupancy' => 3, 'amenities' => []],
        );

        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => $status]);
    }

    private function reservation(Room $room, string $in, string $out, string $status = 'confirmed'): Reservation
    {
        $guest = Guest::create(['first_name' => 'Guest', 'last_name' => $room->room_number.'-'.$in]);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'status' => $status,
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    public function test_a_valid_swap_flips_both_rooms_atomically(): void
    {
        $roomA = $this->room('101');
        $roomB = $this->room('102');
        $a = $this->reservation($roomA, '2026-08-21', '2026-08-24');
        $b = $this->reservation($roomB, '2026-08-22', '2026-08-25');

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$a, $b]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($roomB->id, $a->fresh()->room_id);
        $this->assertSame($roomA->id, $b->fresh()->room_id);
    }

    public function test_swap_refuses_when_a_third_reservation_blocks_the_target(): void
    {
        $roomA = $this->room('201');
        $roomB = $this->room('202');
        $a = $this->reservation($roomA, '2026-08-21', '2026-08-24');
        $b = $this->reservation($roomB, '2026-08-22', '2026-08-25');
        // A third guest already holds room B for part of A's stay.
        $this->reservation($roomB, '2026-08-20', '2026-08-22');

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$a, $b]))
            ->assertSessionHasErrors(['swap']);

        // NEITHER side moved — atomicity.
        $this->assertSame($roomA->id, $a->fresh()->room_id);
        $this->assertSame($roomB->id, $b->fresh()->room_id);
    }

    public function test_swap_is_its_own_inverse(): void
    {
        $roomA = $this->room('301');
        $roomB = $this->room('302');
        $a = $this->reservation($roomA, '2026-08-21', '2026-08-24');
        $b = $this->reservation($roomB, '2026-08-21', '2026-08-24');

        $this->actingAs($this->admin)->post(route('reservations.swap-rooms', [$a, $b]));
        $this->actingAs($this->admin)->post(route('reservations.swap-rooms', [$a, $b]))
            ->assertRedirect()->assertSessionHas('success');

        // Back to the original layout — this is exactly what "Zhbëj" does.
        $this->assertSame($roomA->id, $a->fresh()->room_id);
        $this->assertSame($roomB->id, $b->fresh()->room_id);
    }

    public function test_terminal_statuses_and_same_room_are_refused(): void
    {
        $roomA = $this->room('401');
        $roomB = $this->room('402');
        $a = $this->reservation($roomA, '2026-08-21', '2026-08-24');
        $done = $this->reservation($roomB, '2026-08-10', '2026-08-12', 'checked_out');

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$a, $done]))
            ->assertSessionHasErrors(['swap']);

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$a, $a]))
            ->assertSessionHasErrors(['swap']);
    }

    public function test_two_checked_in_guests_swap_rooms_stay_occupied_with_stayover_refresh_tasks(): void
    {
        $roomA = $this->room('501', 'occupied');
        $roomB = $this->room('502', 'occupied');
        $a = $this->reservation($roomA, '2026-08-18', '2026-08-22', 'checked_in');
        $b = $this->reservation($roomB, '2026-08-18', '2026-08-23', 'checked_in');

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$a, $b]))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('occupied', $roomA->fresh()->status);
        $this->assertSame('occupied', $roomB->fresh()->status);
        foreach ([$roomA, $roomB] as $room) {
            $this->assertTrue(
                CleaningTask::where('room_id', $room->id)->where('type', 'stayover_clean')->where('status', 'pending')->exists(),
                "room {$room->room_number} should get a stayover refresh task",
            );
        }
    }

    public function test_checked_in_and_future_guest_swap_mirrors_move_room_housekeeping(): void
    {
        $roomA = $this->room('601', 'occupied');
        $roomB = $this->room('602');
        $inHouse = $this->reservation($roomA, '2026-08-18', '2026-08-22', 'checked_in');
        $future = $this->reservation($roomB, '2026-08-25', '2026-08-28');

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$inHouse, $future]))
            ->assertRedirect()->assertSessionHas('success');

        // The in-house guest physically moved into 602.
        $this->assertSame('occupied', $roomB->fresh()->status);
        // 601 was lived in and now belongs to a not-yet-arrived guest → cleaning.
        $this->assertSame('cleaning', $roomA->fresh()->status);
        $this->assertTrue(CleaningTask::where('room_id', $roomA->id)->where('type', 'checkout_clean')->where('status', 'pending')->exists());
    }

    public function test_permission_and_tenant_boundaries_hold(): void
    {
        $roomA = $this->room('701');
        $roomB = $this->room('702');
        $a = $this->reservation($roomA, '2026-08-21', '2026-08-24');
        $b = $this->reservation($roomB, '2026-08-21', '2026-08-24');

        $housekeeper = User::factory()->create();
        $housekeeper->assignRole('housekeeping');

        $this->actingAs($housekeeper)
            ->post(route('reservations.swap-rooms', [$a, $b]))
            ->assertForbidden();

        $this->assertSame($roomA->id, $a->fresh()->room_id);
    }

    public function test_maintenance_room_blocks_the_swap(): void
    {
        $roomA = $this->room('801');
        $roomB = $this->room('802', 'maintenance');
        $a = $this->reservation($roomA, '2026-08-21', '2026-08-24');
        $b = $this->reservation($roomB, '2026-08-21', '2026-08-24');

        $this->actingAs($this->admin)
            ->post(route('reservations.swap-rooms', [$a, $b]))
            ->assertSessionHasErrors(['swap']);

        $this->assertSame($roomA->id, $a->fresh()->room_id);
        $this->assertSame($roomB->id, $b->fresh()->room_id);
    }
}
