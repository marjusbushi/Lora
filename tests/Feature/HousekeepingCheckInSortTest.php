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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Renato (2026-08-18): the housekeeping board sorts by the room's SOONEST
 * incoming arrival — clean first where a guest lands next. Today (or overdue)
 * renders red, tomorrow yellow; rooms with no incoming arrival sort last.
 */
class HousekeepingCheckInSortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-18 10:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function roomWithTask(string $number): Room
    {
        $type = RoomType::firstOrCreate(
            ['name' => 'Std'],
            ['base_price' => 100, 'max_occupancy' => 3, 'amenities' => []],
        );
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'cleaning']);
        CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'pending', 'priority' => 'normal']);

        return $room;
    }

    private function arrival(Room $room, string $checkIn, string $status = 'confirmed'): Reservation
    {
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest '.$room->room_number.' '.$checkIn]);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => $checkIn,
            'check_out_date' => CarbonImmutable::parse($checkIn)->addDays(2)->toDateString(),
            'status' => $status,
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    private function boardOrder(): array
    {
        $response = $this->actingAs($this->admin)->get(route('housekeeping.index'));
        $response->assertOk();

        $rooms = [];
        $response->assertInertia(function (AssertableInertia $page) use (&$rooms) {
            $rooms = collect($page->toArray()['props']['tasks']['data'])
                ->map(fn ($task) => $task['room']['room_number'])
                ->all();
        });

        return $rooms;
    }

    public function test_tasks_sort_by_the_soonest_incoming_arrival_with_no_arrival_last(): void
    {
        $later = $this->roomWithTask('301');   // check-in in 5 days
        $today = $this->roomWithTask('302');   // check-in today
        $none = $this->roomWithTask('303');    // no incoming arrival
        $tomorrow = $this->roomWithTask('304'); // check-in tomorrow

        $this->arrival($later, '2026-08-23');
        $this->arrival($today, '2026-08-18');
        $this->arrival($tomorrow, '2026-08-19');

        $this->assertSame(['302', '304', '301', '303'], $this->boardOrder());
    }

    public function test_an_overdue_arrival_sorts_first_of_all(): void
    {
        $today = $this->roomWithTask('401');
        $overdue = $this->roomWithTask('402'); // guest expected since yesterday

        $this->arrival($today, '2026-08-18');
        $this->arrival($overdue, '2026-08-17');

        $this->assertSame(['402', '401'], $this->boardOrder());
    }

    public function test_a_stale_old_arrival_does_not_paint_the_room(): void
    {
        // Overdue counts only back to YESTERDAY. Older confirmed rows are the
        // unmarked-no-show backlog (#7536) — an unbounded window would paint
        // rooms red forever and drown today's real urgency.
        $stale = $this->roomWithTask('411');   // "arrival" three weeks ago
        $tomorrow = $this->roomWithTask('412');

        $this->arrival($stale, '2026-07-28');
        $this->arrival($tomorrow, '2026-08-19');

        $this->assertSame(['412', '411'], $this->boardOrder());

        $this->actingAs($this->admin)->get(route('housekeeping.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasks.data.1.room.room_number', '411')
                ->where('tasks.data.1.next_check_in', null));
    }

    public function test_terminal_and_in_house_reservations_do_not_count_as_incoming(): void
    {
        $withNoise = $this->roomWithTask('501');
        $realTomorrow = $this->roomWithTask('502');

        // None of these should make room 501 urgent.
        $this->arrival($withNoise, '2026-08-18', 'cancelled');
        $this->arrival($withNoise, '2026-08-18', 'checked_in');
        $this->arrival($withNoise, '2026-08-16', 'checked_out');
        $this->arrival($realTomorrow, '2026-08-19');

        $this->assertSame(['502', '501'], $this->boardOrder());
    }

    public function test_the_payload_carries_next_check_in_for_the_ui(): void
    {
        $room = $this->roomWithTask('601');
        $this->arrival($room, '2026-08-19');

        $this->actingAs($this->admin)->get(route('housekeeping.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasks.data.0.room.room_number', '601')
                ->where('tasks.data.0.next_check_in', fn ($value) => str_starts_with((string) $value, '2026-08-19')));
    }

    public function test_status_grouping_still_outranks_arrival_proximity(): void
    {
        // A completed task with today's arrival must NOT outrank actionable work.
        $done = $this->roomWithTask('701');
        CleaningTask::where('room_id', $done->id)->update(['status' => 'completed']);
        $this->arrival($done, '2026-08-18');

        $pendingLater = $this->roomWithTask('702');
        $this->arrival($pendingLater, '2026-08-23');

        $this->assertSame(['702', '701'], $this->boardOrder());
    }
}
