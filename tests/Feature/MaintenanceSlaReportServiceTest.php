<?php

namespace Tests\Feature;

use App\Models\MaintenanceIssue;
use App\Models\MaintenanceIssueEvent;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\MaintenanceSlaReportService;
use App\Services\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceSlaReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_sla_resolution_and_historical_downtime(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);

        $resolved = MaintenanceIssue::create([
            'room_id' => $room->id, 'reported_by' => $user->id, 'assigned_to' => $user->id,
            'title' => 'AC failure', 'category' => 'climate', 'priority' => 'critical', 'status' => 'verified',
            'due_at' => '2026-07-10 13:00:00', 'started_at' => '2026-07-10 09:00:00',
            'resolved_at' => '2026-07-10 12:00:00', 'verified_at' => '2026-07-10 12:00:00',
        ]);
        $resolved->forceFill(['created_at' => '2026-07-10 08:00:00', 'updated_at' => '2026-07-10 12:00:00'])->saveQuietly();
        $this->event($resolved, $user, 'room_blocked', '2026-07-10 08:00:00');
        $this->event($resolved, $user, 'room_released', '2026-07-10 12:00:00');

        $overdue = MaintenanceIssue::create([
            'reported_by' => $user->id, 'title' => 'Lamp failure', 'category' => 'electrical',
            'priority' => 'medium', 'status' => 'reported', 'due_at' => '2026-07-10 11:00:00',
        ]);
        $overdue->forceFill(['created_at' => '2026-07-10 10:00:00', 'updated_at' => '2026-07-10 10:00:00'])->saveQuietly();

        $report = app(MaintenanceSlaReportService::class)->summary(new ReportingPeriod('2026-07-10', '2026-07-10'));

        $this->assertSame(2, $report['summary']['reported']);
        $this->assertSame(1, $report['summary']['resolved']);
        $this->assertSame(1, $report['summary']['overdue']);
        $this->assertSame(100.0, $report['summary']['sla_rate']);
        $this->assertSame(1.0, $report['summary']['avg_response_hours']);
        $this->assertSame(4.0, $report['summary']['avg_resolution_hours']);
        $this->assertSame(4.0, $report['summary']['downtime_hours']);
        $this->assertSame(1, $report['summary']['affected_rooms']);
    }

    public function test_it_caps_live_snapshots_merges_downtime_and_ignores_old_rooms(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $oldRoom = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'available']);

        $first = $this->issue($room, $user, 'First fault', '2026-07-18 08:00:00', '2026-07-25 09:00:00');
        $second = $this->issue($room, $user, 'Second fault', '2026-07-18 09:00:00', null);
        $old = $this->issue($oldRoom, $user, 'Old fault', '2026-06-01 08:00:00', '2026-06-01 10:00:00');
        $this->event($first, $user, 'room_blocked', '2026-07-18 08:00:00');
        $this->event($first, $user, 'room_released', '2026-07-18 11:00:00');
        $this->event($second, $user, 'room_blocked', '2026-07-18 09:00:00');
        $this->event($second, $user, 'room_released', '2026-07-18 12:00:00');
        $this->event($old, $user, 'room_blocked', '2026-06-01 08:00:00');
        $this->event($old, $user, 'room_released', '2026-06-01 10:00:00');

        $report = app(MaintenanceSlaReportService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));

        $this->assertSame(4.0, $report['summary']['downtime_hours']);
        $this->assertSame(1, $report['summary']['affected_rooms']);
        $this->assertSame(0.0, $report['summary']['avg_response_hours']);
        $this->assertSame(0, $report['summary']['overdue']);
        CarbonImmutable::setTestNow();
    }

    public function test_it_treats_reopened_issues_as_open(): void
    {
        $user = User::factory()->create();
        $issue = MaintenanceIssue::create([
            'reported_by' => $user->id, 'title' => 'Recurring leak', 'category' => 'plumbing',
            'priority' => 'high', 'status' => 'in_progress', 'due_at' => '2026-07-10 12:00:00',
            'resolved_at' => '2026-07-10 10:00:00',
        ]);
        $issue->forceFill(['created_at' => '2026-07-10 08:00:00', 'updated_at' => '2026-07-10 11:00:00'])->saveQuietly();
        $this->statusEvent($issue, $user, 'resolved', '2026-07-10 10:00:00');
        $this->statusEvent($issue, $user, 'in_progress', '2026-07-10 11:00:00');

        $report = app(MaintenanceSlaReportService::class)->summary(new ReportingPeriod('2026-07-10', '2026-07-10'));

        $this->assertSame(0, $report['summary']['resolved']);
        $this->assertSame(1, $report['summary']['open']);
        $this->assertSame('in_progress', $report['issues'][0]['status']);
        $this->assertNull($report['issues'][0]['resolution_hours']);
    }

    private function issue(Room $room, User $user, string $title, string $createdAt, ?string $startedAt): MaintenanceIssue
    {
        $issue = MaintenanceIssue::create([
            'room_id' => $room->id, 'reported_by' => $user->id, 'title' => $title,
            'category' => 'other', 'priority' => 'medium', 'status' => 'reported', 'started_at' => $startedAt,
        ]);
        $issue->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $issue;
    }

    private function statusEvent(MaintenanceIssue $issue, User $user, string $to, string $createdAt): void
    {
        $event = MaintenanceIssueEvent::create([
            'maintenance_issue_id' => $issue->id, 'user_id' => $user->id,
            'type' => 'status_changed', 'to_status' => $to,
        ]);
        $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }

    private function event(MaintenanceIssue $issue, User $user, string $type, string $createdAt): void
    {
        $event = MaintenanceIssueEvent::create(['maintenance_issue_id' => $issue->id, 'user_id' => $user->id, 'type' => $type]);
        $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }
}
