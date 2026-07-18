<?php

namespace Tests\Feature;

use App\Models\MaintenanceIssue;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\RecurringMaintenanceIssueService;
use App\Services\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringMaintenanceIssueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detects_structured_repeat_patterns_without_merging_different_rooms(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room101 = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $room102 = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'available']);

        $this->issue($room101, $user, 'AC stopped', 'climate', '2026-01-10 09:00:00', 'AC-101', 'Air conditioner');
        $this->issue($room101, $user, 'No cooling', 'climate', '2026-07-05 09:00:00', ' ac-101 ', 'Air conditioner');
        $this->issue($room101, $user, 'AC alarm', 'climate', '2026-07-10 09:00:00', 'AC-101', 'Air conditioner');
        $this->issue($room101, $user, 'Sink leak', 'plumbing', '2026-06-20 09:00:00', null, 'Sink');
        $this->issue($room101, $user, 'Water under sink', 'plumbing', '2026-07-08 09:00:00', null, ' sink ');
        $this->issue($room101, $user, 'Loose handle', 'furniture', '2026-07-07 09:00:00');
        $this->issue($room102, $user, 'Loose handle', 'furniture', '2026-07-07 10:00:00');
        $this->issue($room101, $user, 'Future repeat', 'climate', '2026-07-20 09:00:00', 'AC-101');

        $report = app(RecurringMaintenanceIssueService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));

        $this->assertSame(2, $report['summary']['recurring_groups']);
        $this->assertSame(3, $report['summary']['repeat_occurrences']);
        $this->assertSame(60.0, $report['summary']['repeat_rate']);
        $this->assertSame(1, $report['summary']['affected_rooms']);
        $this->assertSame(2, $report['groups'][0]['period_occurrences']);
        $this->assertSame(3, $report['groups'][0]['total_occurrences']);
        $this->assertSame('AC-101', $report['groups'][0]['asset_code']);

        CarbonImmutable::setTestNow();
    }

    private function issue(
        Room $room,
        User $user,
        string $title,
        string $category,
        string $createdAt,
        ?string $assetCode = null,
        ?string $assetName = null,
    ): MaintenanceIssue {
        $issue = MaintenanceIssue::create([
            'room_id' => $room->id,
            'reported_by' => $user->id,
            'title' => $title,
            'category' => $category,
            'priority' => 'medium',
            'status' => 'reported',
            'asset_code' => $assetCode,
            'asset_name' => $assetName,
        ]);
        $issue->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $issue;
    }
}
