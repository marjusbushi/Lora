<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\MaintenanceIssue;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RoomStatusReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeRoom(RoomType $type, string $number, string $status): Room
    {
        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => $status]);
    }

    private function checkIn(Room $room, Guest $guest, User $user): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => today()->subDay()->toDateString(), 'check_out_date' => today()->addDay()->toDateString(),
            'status' => 'checked_in', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);
    }

    public function test_each_drift_class_is_flagged_with_its_exact_reason(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // The four drift classes:
        $this->makeRoom($type, '101', 'occupied');                                    // no guest → occupied_no_guest
        $this->checkIn($this->makeRoom($type, '102', 'available'), $guest, $admin);   // guest inside → guest_not_occupied
        $this->makeRoom($type, '103', 'cleaning');                                    // no task → cleaning_no_task (the 308 class)
        $this->makeRoom($type, '104', 'maintenance');                                 // no issue → maintenance_no_issue
        // Healthy counterparts — must NOT be flagged:
        $this->checkIn($this->makeRoom($type, '105', 'occupied'), $guest, $admin);
        CleaningTask::create(['room_id' => $this->makeRoom($type, '106', 'cleaning')->id, 'type' => 'checkout_clean', 'status' => 'pending']);

        $this->actingAs($admin)
            ->get(route('reports.roomStatus'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/RoomStatus')
                ->where('counts.stale', 4)
                ->where('rows', function ($rows) {
                    $byRoom = collect($rows)->keyBy('room_number');

                    return $byRoom['101']['stale'] === 'occupied_no_guest'
                        && $byRoom['102']['stale'] === 'guest_not_occupied'
                        && $byRoom['103']['stale'] === 'cleaning_no_task'
                        && $byRoom['104']['stale'] === 'maintenance_no_issue'
                        && $byRoom['105']['stale'] === null
                        && $byRoom['106']['stale'] === null;
                }));
    }

    public function test_open_maintenance_issue_marks_the_room_without_blocking_it(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);

        // Open issue on an unblocked room (the 303/308 pattern on Saturn).
        $marked = $this->makeRoom($type, '201', 'available');
        MaintenanceIssue::create([
            'room_id' => $marked->id, 'reported_by' => $admin->id,
            'title' => 'Brava e derës', 'status' => 'reported', 'room_blocked' => false,
        ]);
        // Blocked room WITH its issue — healthy, but still marked.
        $blocked = $this->makeRoom($type, '202', 'maintenance');
        MaintenanceIssue::create([
            'room_id' => $blocked->id, 'reported_by' => $admin->id,
            'title' => 'Bojler', 'status' => 'in_progress', 'room_blocked' => true,
        ]);
        // A verified (closed) issue must NOT mark the room.
        $clean = $this->makeRoom($type, '203', 'available');
        MaintenanceIssue::create([
            'room_id' => $clean->id, 'reported_by' => $admin->id,
            'title' => 'Historik', 'status' => 'verified', 'room_blocked' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.roomStatus'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/RoomStatus')
                ->where('counts.stale', 0)
                ->where('rows', function ($rows) {
                    $byRoom = collect($rows)->keyBy('room_number');

                    return $byRoom['201']['maintenance_open'] === true && $byRoom['201']['stale'] === null
                        && $byRoom['202']['maintenance_open'] === true && $byRoom['202']['stale'] === null
                        && $byRoom['203']['maintenance_open'] === false;
                }));
    }

    public function test_healthy_hotel_reports_zero_stale(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $this->makeRoom($type, '101', 'available');
        $this->makeRoom($type, '102', 'available');

        $this->actingAs($admin)
            ->get(route('reports.roomStatus'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/RoomStatus')
                ->where('counts.stale', 0)
                ->where('counts.total', 2)
                ->where('rows.0.stale', null)
                ->where('rows.0.maintenance_open', false));
    }
}
