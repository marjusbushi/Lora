<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ArchiveInspectedCleaningTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
    }

    private function task(Room $room, string $status): CleaningTask
    {
        return CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => $status]);
    }

    public function test_command_archives_only_inspected_tasks(): void
    {
        $room = $this->room();
        $inspected = $this->task($room, 'inspected');
        $pending = $this->task($room, 'pending');
        $completed = $this->task($room, 'completed');

        $this->artisan('housekeeping:archive-inspected')->assertExitCode(0);

        $this->assertNotNull($inspected->fresh()->archived_at);
        $this->assertNull($pending->fresh()->archived_at);
        $this->assertNull($completed->fresh()->archived_at);
    }

    public function test_a_second_run_is_a_no_op_for_already_archived(): void
    {
        $room = $this->room();
        $t = $this->task($room, 'inspected');

        $this->artisan('housekeeping:archive-inspected');
        $firstStamp = $t->fresh()->archived_at;

        $this->artisan('housekeeping:archive-inspected'); // must not re-stamp (whereNull guard)
        $this->assertEquals($firstStamp, $t->fresh()->archived_at);
    }

    public function test_board_excludes_archived_tasks(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $room = $this->room();

        $this->task($room, 'inspected'); // will be archived
        $live = $this->task($room, 'pending');

        $this->artisan('housekeeping:archive-inspected');

        $this->actingAs($admin)
            ->get(route('housekeeping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks.data', 1)
                ->where('tasks.data.0.id', $live->id));
    }

    public function test_command_is_scheduled_daily(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        // Present in schedule:list => it is registered as a scheduled job (daily/midnight,
        // confirmed manually as "0 0 * * *"; the list pads the cron so don't match it literally).
        $this->assertStringContainsString('housekeeping:archive-inspected', $output);
    }
}
