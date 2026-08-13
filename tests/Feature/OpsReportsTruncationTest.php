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

class OpsReportsTruncationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_housekeeping_surplus_is_declared_not_swallowed(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);

        // 53 unassigned tasks today: the list caps at 50, the other 3 must be
        // declared — and the staff table groups them under the 'Unassigned'
        // sentinel the frontend translates.
        foreach (range(1, 53) as $i) {
            CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'pending']);
        }

        $this->actingAs($admin)
            ->get(route('reports.housekeepingReport', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Housekeeping')
                ->where('analytics.tasks_truncated', 3)
                ->where('analytics.tasks', fn ($tasks) => count($tasks) === 50)
                ->where('analytics.summary.total', 53)
                ->where('analytics.staff.0.staff', 'Unassigned'));
    }

    public function test_small_datasets_report_zero_truncated_on_all_three_reports(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'pending']);
        MaintenanceIssue::create([
            'room_id' => $room->id, 'reported_by' => $admin->id,
            'title' => 'Brava', 'status' => 'reported', 'room_blocked' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.housekeepingReport'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('analytics.tasks_truncated', 0));

        $this->actingAs($admin)
            ->get(route('reports.maintenanceSla'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('analytics.issues_truncated', 0));

        $this->actingAs($admin)
            ->get(route('reports.recurringMaintenance'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('analytics.groups_truncated', 0));
    }
}
