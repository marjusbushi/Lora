<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\MaintenanceIssue;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MaintenanceDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function room(): Room
    {
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '204', 'floor' => 2, 'status' => 'available']);
    }

    public function test_guest_cannot_open_maintenance(): void
    {
        $this->get(route('maintenance.index'))->assertRedirect(route('login'));
    }

    public function test_authorized_staff_can_open_live_maintenance_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('maintenance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Maintenance/Design')
                ->has('issues.data')
                ->has('rooms')
                ->has('staff')
                ->has('stats'));
    }

    public function test_full_workflow_blocks_then_restores_room_after_verification(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create();
        $room = $this->room();

        $this->actingAs($admin)->post(route('maintenance.store'), [
            'room_id' => $room->id,
            'title' => 'Kondicioneri nuk ftoh',
            'description' => 'Duhet kontrolluar.',
            'category' => 'climate',
            'kind' => 'corrective',
            'priority' => 'critical',
            'block_room' => true,
        ])->assertSessionHasNoErrors();

        $issue = MaintenanceIssue::firstOrFail();
        $this->assertSame('reported', $issue->status);
        $this->assertSame('maintenance', $room->fresh()->status);

        $this->actingAs($admin)->patch(route('maintenance.assign', $issue), ['assigned_to' => $technician->id])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('maintenance.status', $issue), ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('maintenance.status', $issue), ['status' => 'resolved', 'note' => 'U ndërrua filtri.'])->assertSessionHasNoErrors();

        $this->assertSame('maintenance', $room->fresh()->status, 'Dhoma nuk lirohet pa verifikim.');

        $this->actingAs($admin)->patch(route('maintenance.status', $issue), ['status' => 'verified'])->assertSessionHasNoErrors();

        $this->assertSame('verified', $issue->fresh()->status);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertFalse($issue->fresh()->room_blocked);
        $this->assertCount(5, $issue->events);
    }

    public function test_report_from_housekeeping_creates_linked_maintenance_issue(): void
    {
        $housekeeper = User::factory()->create();
        $housekeeper->assignRole('housekeeping');
        $room = $this->room();
        $task = CleaningTask::create([
            'room_id' => $room->id,
            'assigned_to' => $housekeeper->id,
            'type' => 'stayover_clean',
            'status' => 'in_progress',
            'priority' => 'normal',
        ]);

        $this->actingAs($housekeeper)->post(route('housekeeping.issue', $task), [
            'issue_reported' => 'Televizori nuk ndizet',
            'set_maintenance' => true,
        ])->assertSessionHasNoErrors();

        $issue = MaintenanceIssue::firstOrFail();
        $this->assertSame($task->id, $issue->cleaning_task_id);
        $this->assertSame('housekeeping', $issue->source);
        $this->assertTrue($issue->room_blocked);
        $this->assertSame('maintenance', $room->fresh()->status);
    }
}
