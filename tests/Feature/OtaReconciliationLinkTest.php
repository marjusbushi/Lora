<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\OtaReconciliationIssue;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reconciliation panel's ONLY write action: linking a suggested manual
 * reservation to the OTA booking behind an audit issue. Guarded to
 * staff-entered, unlinked, active candidates; audit-logged; and it resolves
 * the unlinked-twin family of issues when Channex is not reachable.
 */
class OtaReconciliationLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Room $room;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // The reconciliation routes sit behind the channel_manager module gate.
        $tenant = Tenant::query()->sole();
        $tenant->update(['metadata' => array_merge($tenant->metadata ?? [], [
            'billing_access' => ['status' => 'active', 'modules' => ['channel_manager' => true]],
        ])]);

        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $this->room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $this->guest = Guest::create(['first_name' => 'Krystof', 'last_name' => 'Novotny']);
    }

    private function manualReservation(array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 288,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => null,
        ], $overrides));
    }

    private function issue(Reservation $candidate, array $overrides = []): OtaReconciliationIssue
    {
        return OtaReconciliationIssue::create(array_merge([
            'channel' => 'booking.com',
            'external_ref' => '5827243178',
            'issue_type' => 'possible_manual_duplicate',
            'severity' => 'warning',
            'status' => OtaReconciliationIssue::STATUS_OPEN,
            'details' => ['candidate_reservation_ids' => [$candidate->id]],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ], $overrides));
    }

    public function test_links_the_candidate_and_resolves_the_twin_issue(): void
    {
        $manual = $this->manualReservation();
        $issue = $this->issue($manual);

        $this->actingAs($this->admin)
            ->post(route('reservations.reconciliation.link', $issue), ['reservation_id' => $manual->id])
            ->assertRedirect()->assertSessionHas('success');

        $fresh = $manual->fresh();
        $this->assertSame('booking.com', $fresh->channel);
        $this->assertSame('5827243178', $fresh->channel_ref);
        // Channex is not configured in tests -> the fallback resolves the issue.
        $this->assertSame(OtaReconciliationIssue::STATUS_RESOLVED, $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->resolved_at);

        $log = AuditLog::where('action', 'reservation.link_ota')->sole();
        $this->assertSame($manual->id, (int) $log->subject_id);
        $this->assertSame($this->admin->id, (int) $log->causer_id);
        $this->assertSame('5827243178', $log->properties['channel_ref']);
    }

    public function test_novotny_shape_ref_held_only_by_a_cancelled_row_links_fine(): void
    {
        // The OTA copy was cancelled by the desk; the ref sits on the dead row.
        Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
            'status' => 'cancelled',
            'total_amount' => 288,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => '5827243178',
        ]);
        $manual = $this->manualReservation();
        $issue = $this->issue($manual);

        $this->actingAs($this->admin)
            ->post(route('reservations.reconciliation.link', $issue), ['reservation_id' => $manual->id])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('5827243178', $manual->fresh()->channel_ref);
    }

    public function test_refuses_when_an_active_reservation_already_holds_the_ref(): void
    {
        Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 288,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => '5827243178',
        ]);
        $manual = $this->manualReservation();
        $issue = $this->issue($manual);

        $this->actingAs($this->admin)
            ->post(route('reservations.reconciliation.link', $issue), ['reservation_id' => $manual->id])
            ->assertSessionHasErrors('reservation_id');

        $this->assertNull($manual->fresh()->channel_ref);
        $this->assertSame(OtaReconciliationIssue::STATUS_OPEN, $issue->fresh()->status);
    }

    public function test_refuses_a_reservation_that_is_not_a_candidate_of_the_issue(): void
    {
        $manual = $this->manualReservation();
        $other = $this->manualReservation(['check_in_date' => now()->addDays(20)->toDateString(), 'check_out_date' => now()->addDays(22)->toDateString()]);
        $issue = $this->issue($manual);

        $this->actingAs($this->admin)
            ->post(route('reservations.reconciliation.link', $issue), ['reservation_id' => $other->id])
            ->assertSessionHasErrors('reservation_id');

        $this->assertNull($other->fresh()->channel_ref);
    }

    public function test_refuses_synced_or_already_linked_candidates(): void
    {
        $synced = $this->manualReservation(['created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER]);
        $issue = $this->issue($synced);

        $this->actingAs($this->admin)
            ->post(route('reservations.reconciliation.link', $issue), ['reservation_id' => $synced->id])
            ->assertSessionHasErrors('reservation_id');

        $linked = $this->manualReservation(['channel_ref' => '9999999999', 'check_in_date' => now()->addDays(20)->toDateString(), 'check_out_date' => now()->addDays(22)->toDateString()]);
        $issue2 = $this->issue($linked, ['external_ref' => '6666666666']);

        $this->actingAs($this->admin)
            ->post(route('reservations.reconciliation.link', $issue2), ['reservation_id' => $linked->id])
            ->assertSessionHasErrors('reservation_id');

        $this->assertSame('9999999999', $linked->fresh()->channel_ref);
    }

    public function test_requires_the_update_reservations_permission(): void
    {
        $manual = $this->manualReservation();
        $issue = $this->issue($manual);
        $nobody = User::factory()->create();

        $this->actingAs($nobody)
            ->post(route('reservations.reconciliation.link', $issue), ['reservation_id' => $manual->id])
            ->assertForbidden();

        $this->assertNull($manual->fresh()->channel_ref);
    }
}
