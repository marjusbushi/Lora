<?php

namespace Tests\Feature;

use App\Models\MaintenanceIssue;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\RecurringMaintenanceIssueService;
use App\Services\Reporting\ReportingPeriod;
use App\Support\MaintenanceIssueTypes;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceIssueTypeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeRoom(string $number): Room
    {
        $type = RoomType::firstOrCreate(['name' => 'Std'], ['base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'available']);
    }

    private function issue(Room $room, User $user, array $attrs): MaintenanceIssue
    {
        return MaintenanceIssue::create($attrs + [
            'room_id' => $room->id, 'reported_by' => $user->id,
            'category' => 'furniture', 'priority' => 'medium', 'status' => 'reported',
        ]);
    }

    public function test_catalog_and_labels_are_in_sync(): void
    {
        // Every key has an Albanian label; no orphan labels.
        $this->assertEqualsCanonicalizing(MaintenanceIssueTypes::keys(), array_keys(MaintenanceIssueTypes::LABELS_SQ));
    }

    public function test_store_derives_title_from_the_catalog_key(): void
    {
        $admin = $this->admin();
        $room = $this->makeRoom('101');

        $this->actingAs($admin)->post(route('maintenance.store'), [
            'room_id' => $room->id, 'issue_key' => 'door_lock_broken',
            'category' => 'furniture', 'kind' => 'corrective', 'priority' => 'high',
        ])->assertSessionHasNoErrors();

        $issue = MaintenanceIssue::firstOrFail();
        $this->assertSame('door_lock_broken', $issue->issue_key);
        $this->assertSame('Brava e derës e prishur', $issue->title);
    }

    public function test_manual_title_required_without_key_and_invalid_keys_rejected(): void
    {
        $admin = $this->admin();
        $room = $this->makeRoom('101');

        // "Tjetër" without a description → error.
        $this->actingAs($admin)->post(route('maintenance.store'), [
            'room_id' => $room->id, 'category' => 'other', 'kind' => 'corrective', 'priority' => 'low',
        ])->assertSessionHasErrors(['title']);

        // A key outside the catalog → error.
        $this->actingAs($admin)->post(route('maintenance.store'), [
            'room_id' => $room->id, 'issue_key' => 'made_up_key',
            'category' => 'other', 'kind' => 'corrective', 'priority' => 'low',
        ])->assertSessionHasErrors(['issue_key']);

        // "Tjetër" WITH a manual description → stored with null key.
        $this->actingAs($admin)->post(route('maintenance.store'), [
            'room_id' => $room->id, 'title' => 'Diçka krejt e veçantë',
            'category' => 'other', 'kind' => 'corrective', 'priority' => 'low',
        ])->assertSessionHasNoErrors();
        $this->assertNull(MaintenanceIssue::firstOrFail()->issue_key);
    }

    public function test_recurrence_groups_by_key_regardless_of_wording(): void
    {
        $admin = $this->admin();
        $room = $this->makeRoom('308');
        $other = $this->makeRoom('101');

        // Same problem, three different wordings — grouped by the key.
        foreach ([['Brava e derës', '-40 days'], ['dera nuk mbyllet', '-20 days'], ['Door lock broken again', '-2 days']] as [$title, $when]) {
            $this->issue($room, $admin, ['title' => $title, 'issue_key' => 'door_lock_broken'])
                ->forceFill(['created_at' => now()->modify($when)])->saveQuietly();
        }
        // Same key in a DIFFERENT room — must NOT join the group.
        $this->issue($other, $admin, ['title' => 'Brava', 'issue_key' => 'door_lock_broken'])
            ->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $summary = app(RecurringMaintenanceIssueService::class)
            ->summary(new ReportingPeriod(now()->subDays(30)->toDateString(), now()->toDateString()));

        $this->assertSame(1, $summary['summary']['recurring_groups']);
        $group = $summary['groups'][0];
        $this->assertSame('308', $group['room']);
        $this->assertSame(3, $group['total_occurrences']);
        $this->assertSame('type:'.$this->roomId('308').':door_lock_broken', $group['key']);
    }

    public function test_titles_still_group_for_keyless_issues(): void
    {
        $admin = $this->admin();
        $room = $this->makeRoom('205');

        // Historical/"Tjetër" rows: identical titles still group (old behavior).
        foreach (['-30 days', '-5 days'] as $when) {
            $this->issue($room, $admin, ['title' => 'Bojleri i vjetër'])
                ->forceFill(['created_at' => now()->modify($when)])->saveQuietly();
        }

        $summary = app(RecurringMaintenanceIssueService::class)
            ->summary(new ReportingPeriod(now()->subDays(30)->toDateString(), now()->toDateString()));

        $this->assertSame(1, $summary['summary']['recurring_groups']);
        $this->assertSame(2, $summary['groups'][0]['total_occurrences']);
    }

    private function roomId(string $number): int
    {
        return (int) Room::where('room_number', $number)->value('id');
    }
}
