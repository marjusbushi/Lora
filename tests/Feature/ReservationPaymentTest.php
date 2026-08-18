<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);

        $this->staff = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $this->staff->tenants()->syncWithoutDetaching([
            $tenant->id => ['is_owner' => true, 'is_active' => true],
        ]);
        $this->staff->assignRole('admin');

        $type = RoomType::create(['name' => 'Standard', 'base_price' => 360, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '301', 'floor' => 3, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Sarah', 'last_name' => 'Johnson']);

        $this->reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->staff->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(4)->toDateString(),
            'status' => 'pending',
            'total_amount' => 360,
            'adults' => 2,
            'channel' => 'direct',
        ]);
    }

    public function test_receptionist_records_checkout_money_through_the_folio(): void
    {
        // Renato (2026-08-18): the desk lost the Financa module entirely —
        // this folio route (gated by update_reservations) is where checkout
        // money flows for reception, and it must keep working.
        $receptionist = User::factory()->create(['current_tenant_id' => $this->staff->current_tenant_id]);
        $receptionist->assignRole('receptionist');

        $this->actingAs($receptionist)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 100,
                'method' => 'cash',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $this->reservation->id,
            'amount' => 100,
            'created_by' => $receptionist->id,
        ]);
    }

    public function test_payment_returns_json_and_is_recorded(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 360,
                'method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Pagesa u regjistrua.');

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $this->reservation->id,
            'amount' => 360,
            'method' => 'cash',
            'created_by' => $this->staff->id,
        ]);
    }

    public function test_payment_cannot_exceed_the_live_outstanding_balance(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 360.01,
                'method' => 'card',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cancelled_reservation_cannot_receive_a_payment(): void
    {
        $this->reservation->update(['status' => 'cancelled']);

        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 10,
                'method' => 'cash',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);

        // Roja e statusit: një i anulluar nuk ringjallet nga një pagesë e refuzuar.
        $this->assertSame('cancelled', $this->reservation->fresh()->status);
    }

    public function test_payment_confirms_a_pending_reservation(): void
    {
        $this->assertSame('pending', $this->reservation->status);

        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 360,
                'method' => 'cash',
            ])
            ->assertCreated();

        $this->assertSame('confirmed', $this->reservation->fresh()->status);
    }

    public function test_a_partial_deposit_also_confirms_the_reservation(): void
    {
        // Vendim i ratifikuar: kapari është shenja që rezervimi është i vërtetë;
        // mbetja mblidhet në check-out.
        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 100,
                'method' => 'cash',
            ])
            ->assertCreated();

        $this->assertSame('confirmed', $this->reservation->fresh()->status);
    }

    public function test_payment_never_drags_an_advanced_status_backwards(): void
    {
        $this->reservation->update(['status' => 'checked_in']);

        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 60,
                'method' => 'cash',
            ])
            ->assertCreated();

        $this->assertSame('checked_in', $this->reservation->fresh()->status);
    }

    public function test_confirm_action_moves_only_pending_forward(): void
    {
        $this->actingAs($this->staff)
            ->post(route('reservations.confirm', $this->reservation))
            ->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $this->reservation->fresh()->status);

        // Thirrja e dytë s'ka çfarë kalon — 0 rreshta të prekur → 422.
        $this->actingAs($this->staff)
            ->postJson(route('reservations.confirm', $this->reservation))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');
    }

    public function test_confirm_never_resurrects_a_cancelled_reservation(): void
    {
        $this->reservation->update(['status' => 'cancelled']);

        $this->actingAs($this->staff)
            ->postJson(route('reservations.confirm', $this->reservation))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');

        $this->assertSame('cancelled', $this->reservation->fresh()->status);
    }

    /**
     * Gjetje P1 e Codex: një update bulk mbi query-builder i kapërcen në heshtje
     * ReservationObserver — pra historia e statuseve (ushqimi i analitikës së
     * çmimeve), AuditLog-u dhe ri-push-i i disponueshmërisë te Channex. Të dyja
     * rrugët e konfirmimit duhet ta lënë gjurmën.
     */
    public function test_both_confirmation_paths_record_the_status_transition(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('reservations.payment', $this->reservation), [
                'amount' => 50,
                'method' => 'cash',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reservation_status_logs', [
            'reservation_id' => $this->reservation->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
        ]);

        $second = Reservation::create([
            'room_id' => $this->reservation->room_id,
            'guest_id' => $this->reservation->guest_id,
            'created_by' => $this->staff->id,
            'check_in_date' => now()->addDays(20)->toDateString(),
            'check_out_date' => now()->addDays(22)->toDateString(),
            'status' => 'pending',
            'total_amount' => 200,
            'adults' => 2,
            'channel' => 'direct',
        ]);

        $this->actingAs($this->staff)
            ->post(route('reservations.confirm', $second))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reservation_status_logs', [
            'reservation_id' => $second->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
        ]);
    }

    public function test_confirm_requires_the_update_reservations_permission(): void
    {
        $housekeeper = User::factory()->create(['current_tenant_id' => $this->staff->current_tenant_id]);
        $housekeeper->tenants()->syncWithoutDetaching([
            $this->staff->current_tenant_id => ['is_owner' => false, 'is_active' => true],
        ]);
        $housekeeper->assignRole('housekeeping');

        $this->actingAs($housekeeper)
            ->post(route('reservations.confirm', $this->reservation))
            ->assertForbidden();

        $this->assertSame('pending', $this->reservation->fresh()->status);
    }
}
