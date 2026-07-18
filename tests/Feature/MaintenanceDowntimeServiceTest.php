<?php

namespace Tests\Feature;

use App\Models\MaintenanceIssue;
use App\Models\MaintenanceIssueEvent;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\MaintenanceDowntimeService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceDowntimeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_block_ends_when_verified_instead_of_when_resolved(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $issue = MaintenanceIssue::create([
            'room_id' => $room->id,
            'reported_by' => $user->id,
            'title' => 'Historical outage',
            'status' => 'verified',
            'room_blocked' => false,
            'previous_room_status' => 'available',
            'resolved_at' => '2026-07-01 12:00:00',
            'verified_at' => '2026-07-02 12:00:00',
        ]);
        $issue->forceFill(['created_at' => '2026-07-01 08:00:00', 'updated_at' => '2026-07-02 12:00:00'])->saveQuietly();

        $this->event($issue, $user, 'reported', null, 'reported', '2026-07-01 08:00:00');
        $this->event($issue, $user, 'status_changed', 'in_progress', 'resolved', '2026-07-01 12:00:00');
        $this->event($issue, $user, 'status_changed', 'resolved', 'verified', '2026-07-02 12:00:00');

        $blocks = app(MaintenanceDowntimeService::class)
            ->forRooms([$room->id], new ReportingPeriod('2026-07-01', '2026-07-02'));

        $this->assertCount(1, $blocks);
        $this->assertSame('2026-07-01 08:00:00', $blocks[0]['starts_at']);
        $this->assertSame('2026-07-02 12:00:00', $blocks[0]['ends_at']);
    }

    public function test_current_maintenance_status_does_not_change_old_comparison_periods(): void
    {
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'maintenance']);

        $blocks = app(MaintenanceDowntimeService::class)
            ->forRooms([$room->id], new ReportingPeriod('2025-07-01', '2025-07-02'));

        $this->assertSame([], $blocks);
    }

    private function event(
        MaintenanceIssue $issue,
        User $user,
        string $type,
        ?string $from,
        ?string $to,
        string $createdAt,
    ): void {
        $event = MaintenanceIssueEvent::create([
            'maintenance_issue_id' => $issue->id,
            'user_id' => $user->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
        ]);
        $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }
}
