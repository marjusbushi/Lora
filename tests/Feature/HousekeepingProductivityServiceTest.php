<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\HousekeepingProductivityService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousekeepingProductivityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_cleaning_productivity_and_turnaround(): void
    {
        $staff = User::factory()->create(['name' => 'Housekeeper One']);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'cleaning']);

        $completed = CleaningTask::create([
            'room_id' => $room->id, 'assigned_to' => $staff->id, 'type' => 'checkout_clean',
            'status' => 'inspected', 'priority' => 'normal', 'started_at' => '2026-07-10 08:15:00',
            'completed_at' => '2026-07-10 09:00:00', 'inspected_at' => '2026-07-10 09:10:00',
        ]);
        $completed->timestamps = false;
        $completed->forceFill(['created_at' => '2026-07-10 08:00:00', 'updated_at' => '2026-07-10 09:10:00'])->saveQuietly();

        $pending = CleaningTask::create([
            'room_id' => $room->id, 'assigned_to' => $staff->id, 'type' => 'stayover_clean',
            'status' => 'pending', 'priority' => 'urgent', 'issue_reported' => 'AC issue',
        ]);
        $pending->timestamps = false;
        $pending->forceFill(['created_at' => '2026-07-10 10:00:00', 'updated_at' => '2026-07-10 10:00:00'])->saveQuietly();

        $carryover = CleaningTask::create([
            'room_id' => $room->id, 'assigned_to' => $staff->id, 'type' => 'checkout_clean',
            'status' => 'completed', 'priority' => 'normal', 'started_at' => '2026-07-01 00:00:00',
            'completed_at' => '2026-07-01 00:30:00',
        ]);
        $carryover->timestamps = false;
        $carryover->forceFill(['created_at' => '2026-06-30 23:45:00', 'updated_at' => '2026-07-01 00:30:00'])->saveQuietly();

        $lateCompletion = CleaningTask::create([
            'room_id' => $room->id, 'assigned_to' => $staff->id, 'type' => 'deep_clean',
            'status' => 'completed', 'priority' => 'normal', 'started_at' => '2026-07-31 23:30:00',
            'completed_at' => '2026-08-01 00:30:00',
        ]);
        $lateCompletion->timestamps = false;
        $lateCompletion->forceFill(['created_at' => '2026-07-31 23:15:00', 'updated_at' => '2026-08-01 00:30:00'])->saveQuietly();

        $report = app(HousekeepingProductivityService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));

        $this->assertSame(3, $report['summary']['total']);
        $this->assertSame(2, $report['summary']['completed']);
        $this->assertSame(2, $report['summary']['pending']);
        $this->assertSame(33.3, $report['summary']['completion_rate']);
        $this->assertSame(37.5, $report['summary']['avg_clean_minutes']);
        $this->assertSame(15.0, $report['summary']['avg_queue_minutes']);
        $this->assertSame(50.0, $report['summary']['inspection_rate']);
        $this->assertSame(1, $report['summary']['issues']);
        $this->assertSame('Housekeeper One', $report['staff'][0]['staff']);
        $this->assertSame(2, $report['staff'][0]['completed']);
        $this->assertSame(1, collect($report['daily'])->firstWhere('date', '2026-07-01')['completed']);
        $this->assertSame(0, collect($report['daily'])->firstWhere('date', '2026-07-31')['completed']);
    }
}
