<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FiscalDocument;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ChannelSync;
use App\Services\ReservationMoney;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarlyDepartureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-27 10:00:00', 'Europe/Tirane'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function stay(float $total = 400, string $channel = 'direct'): Reservation
    {
        $sequence = Room::query()->count() + 1;
        $type = RoomType::create([
            'name' => "Standard {$sequence}",
            'base_price' => 100,
            'max_occupancy' => 3,
            'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => (string) (200 + $sequence),
            'floor' => 2,
            'status' => 'occupied',
        ]);
        $guest = Guest::create(['first_name' => 'Elira', 'last_name' => 'Demo']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => '2026-07-25',
            'check_out_date' => '2026-07-29',
            'status' => 'checked_in',
            'total_amount' => $total,
            'adults' => 2,
            'channel' => $channel,
        ]);
    }

    public function test_waived_early_departure_reprices_used_nights_settles_and_releases_room(): void
    {
        $reservation = $this->stay();

        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-27',
            'policy' => 'waive',
            'reason' => 'Ndryshim i planit të udhëtimit',
            'settle_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame('checked_out', $reservation->status);
        $this->assertSame('2026-07-27', $reservation->check_out_date->toDateString());
        $this->assertSame('2026-07-29', $reservation->original_check_out_date->toDateString());
        $this->assertSame('waive', $reservation->early_departure_policy);
        $this->assertSame('0.00', $reservation->early_departure_penalty_amount);
        $this->assertSame($this->admin->id, $reservation->early_departure_by);
        $this->assertSame('200.00', $reservation->total_amount);
        $this->assertSame('cleaning', $reservation->room->fresh()->status);
        $this->assertDatabaseHas('cleaning_tasks', [
            'room_id' => $reservation->room_id,
            'type' => 'checkout_clean',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('payments', [
            'reservation_id' => $reservation->id,
            'type' => 'payment',
            'method' => 'cash',
            'amount' => 200,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
            'action' => 'reservation.early_departure',
        ]);
        $this->assertEqualsWithDelta(0, ReservationMoney::totals($reservation)['outstanding'], 0.001);
    }

    public function test_partial_penalty_refunds_a_prepaid_overage_and_keeps_net_paid_correct(): void
    {
        $reservation = $this->stay();
        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 300,
            'method' => 'card',
            'type' => 'payment',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-27',
            'policy' => 'partial',
            'penalty_amount' => 50,
            'reason' => 'Klienti përfundoi udhëtimin më herët',
            'refund_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('250.00', $reservation->total_amount);
        $this->assertSame('50.00', $reservation->early_departure_penalty_amount);
        $this->assertDatabaseHas('payments', [
            'reservation_id' => $reservation->id,
            'type' => 'refund',
            'method' => 'cash',
            'amount' => 50,
        ]);
        $totals = ReservationMoney::totals($reservation->fresh());
        $this->assertEqualsWithDelta(250, $totals['paid'], 0.001);
        $this->assertEqualsWithDelta(0, $totals['outstanding'], 0.001);
        $this->assertSame(1, AuditLog::where('action', 'payment.refund')->count());
    }

    public function test_full_charge_keeps_original_total_and_records_unused_value_as_penalty(): void
    {
        $reservation = $this->stay();

        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-27',
            'policy' => 'full',
            'reason' => 'Tarifë e pakthyeshme',
            'settle_method' => 'card',
        ])->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('400.00', $reservation->total_amount);
        $this->assertSame('200.00', $reservation->early_departure_penalty_amount);
        $this->assertEqualsWithDelta(400, (float) $reservation->payments()->sum('amount'), 0.001);
    }

    public function test_early_departure_rolls_back_when_payment_or_refund_method_is_missing(): void
    {
        $unpaid = $this->stay();
        $this->actingAs($this->admin)->post(route('reservations.early-departure', $unpaid), [
            'departure_date' => '2026-07-27',
            'policy' => 'waive',
            'reason' => 'Largim më herët',
        ])->assertSessionHasErrors('settle_method');

        $unpaid->refresh();
        $this->assertSame('checked_in', $unpaid->status);
        $this->assertSame('2026-07-29', $unpaid->check_out_date->toDateString());
        $this->assertSame('400.00', $unpaid->total_amount);
        $this->assertNull($unpaid->early_departure_at);

        $prepaid = $this->stay();
        Payment::create([
            'reservation_id' => $prepaid->id,
            'amount' => 300,
            'method' => 'card',
            'type' => 'payment',
            'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)->post(route('reservations.early-departure', $prepaid), [
            'departure_date' => '2026-07-27',
            'policy' => 'waive',
            'reason' => 'Largim më herët',
        ])->assertSessionHasErrors('refund_method');

        $this->assertSame('checked_in', $prepaid->fresh()->status);
        $this->assertSame(1, $prepaid->payments()->count());
    }

    public function test_future_departure_is_planned_without_checking_out_or_settling_early(): void
    {
        $reservation = $this->stay();
        $roomType = $reservation->room->roomType;

        $before = app(ChannelSync::class)->availabilityByDate(
            $roomType,
            CarbonImmutable::parse('2026-07-28'),
            CarbonImmutable::parse('2026-07-28'),
        );
        $this->assertSame(0, $before['2026-07-28']);

        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-28',
            'policy' => 'waive',
            'reason' => 'Mysafiri na njoftoi që në fillim',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame('checked_in', $reservation->status);
        $this->assertSame('2026-07-28', $reservation->check_out_date->toDateString());
        $this->assertSame('2026-07-29', $reservation->original_check_out_date->toDateString());
        $this->assertSame('400.00', $reservation->early_departure_original_room_total);
        $this->assertSame('300.00', $reservation->total_amount);
        $this->assertNotNull($reservation->early_departure_scheduled_at);
        $this->assertNull($reservation->early_departure_at);
        $this->assertSame('occupied', $reservation->room->fresh()->status);
        $this->assertSame(0, $reservation->payments()->count());
        $this->assertDatabaseMissing('cleaning_tasks', ['room_id' => $reservation->room_id]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
            'action' => 'reservation.early_departure_scheduled',
        ]);

        $after = app(ChannelSync::class)->availabilityByDate(
            $roomType,
            CarbonImmutable::parse('2026-07-28'),
            CarbonImmutable::parse('2026-07-28'),
        );
        $this->assertSame(1, $after['2026-07-28']);
    }

    public function test_planned_departure_is_completed_only_on_the_actual_day(): void
    {
        $reservation = $this->stay();
        $payload = [
            'departure_date' => '2026-07-28',
            'policy' => 'waive',
            'reason' => 'Mysafiri na njoftoi që në fillim',
        ];

        $this->actingAs($this->admin)
            ->post(route('reservations.early-departure', $reservation), $payload)
            ->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2026-07-28 09:00:00', 'Europe/Tirane'));
        $this->actingAs($this->admin)
            ->post(route('reservations.early-departure', $reservation), [
                ...$payload,
                'settle_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('checked_out', $reservation->status);
        $this->assertSame('2026-07-28', $reservation->check_out_date->toDateString());
        $this->assertSame('2026-07-29', $reservation->original_check_out_date->toDateString());
        $this->assertNotNull($reservation->early_departure_scheduled_at);
        $this->assertNotNull($reservation->early_departure_at);
        $this->assertSame('cleaning', $reservation->room->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'reservation_id' => $reservation->id,
            'type' => 'payment',
            'method' => 'cash',
            'amount' => 300,
        ]);
        $this->assertDatabaseHas('cleaning_tasks', [
            'room_id' => $reservation->room_id,
            'type' => 'checkout_clean',
            'status' => 'pending',
        ]);
    }

    public function test_planned_departure_can_be_cancelled_and_inventory_is_restored(): void
    {
        $reservation = $this->stay();
        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-28',
            'policy' => 'partial',
            'penalty_amount' => 50,
            'reason' => 'Plan paraprak',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->delete(route('reservations.early-departure-plan.cancel', $reservation))
            ->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('checked_in', $reservation->status);
        $this->assertSame('2026-07-29', $reservation->check_out_date->toDateString());
        $this->assertSame('400.00', $reservation->total_amount);
        $this->assertNull($reservation->original_check_out_date);
        $this->assertNull($reservation->early_departure_original_room_total);
        $this->assertNull($reservation->early_departure_scheduled_at);
        $this->assertNull($reservation->early_departure_policy);

        $availability = app(ChannelSync::class)->availabilityByDate(
            $reservation->room->roomType,
            CarbonImmutable::parse('2026-07-28'),
            CarbonImmutable::parse('2026-07-28'),
        );
        $this->assertSame(0, $availability['2026-07-28']);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
            'action' => 'reservation.early_departure_plan_cancelled',
        ]);
    }

    public function test_planned_departure_cannot_bypass_the_controlled_completion_flow(): void
    {
        $reservation = $this->stay();
        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-28',
            'policy' => 'waive',
            'reason' => 'Plan paraprak',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('reservations.check-out', $reservation), ['settle_method' => 'cash'])
            ->assertSessionHasErrors('departure_date');

        $reservation->refresh();
        $this->assertSame('checked_in', $reservation->status);
        $this->assertSame(0, $reservation->payments()->count());
        $this->assertSame('occupied', $reservation->room->fresh()->status);
    }

    public function test_early_departure_rejects_invalid_dates_excess_penalty_and_non_checked_in_stays(): void
    {
        $reservation = $this->stay();

        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-29',
            'policy' => 'waive',
            'reason' => 'Nuk është largim i parakohshëm',
        ])->assertSessionHasErrors('departure_date');

        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-27',
            'policy' => 'partial',
            'penalty_amount' => 201,
            'reason' => 'Penalitet i tepërt',
            'settle_method' => 'cash',
        ])->assertSessionHasErrors('penalty_amount');

        $reservation->update(['status' => 'confirmed']);
        $this->actingAs($this->admin)->post(route('reservations.early-departure', $reservation), [
            'departure_date' => '2026-07-27',
            'policy' => 'waive',
            'reason' => 'Status i gabuar',
            'settle_method' => 'cash',
        ])->assertSessionHasErrors('departure_date');

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame(0, $reservation->payments()->count());
    }

    public function test_early_departure_is_blocked_by_open_pos_orders_or_a_fiscalized_invoice(): void
    {
        $withOpenOrder = $this->stay();
        PosOrder::create([
            'reservation_id' => $withOpenOrder->id,
            'status' => 'open',
            'total_amount' => 10,
            'created_by' => $this->admin->id,
        ]);

        $payload = [
            'departure_date' => '2026-07-27',
            'policy' => 'waive',
            'reason' => 'Largim më herët',
            'settle_method' => 'cash',
        ];

        $this->actingAs($this->admin)
            ->post(route('reservations.early-departure', $withOpenOrder), $payload)
            ->assertSessionHasErrors('departure_date');

        $this->assertSame('checked_in', $withOpenOrder->fresh()->status);
        $this->assertSame(0, $withOpenOrder->payments()->count());

        $fiscalized = $this->stay();
        FiscalDocument::create([
            'reservation_id' => $fiscalized->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'RES-'.$fiscalized->id,
            'payment_method' => 'BANKNOTE',
            'currency' => 'EUR',
            'total' => 400,
            'vat_rate' => 6,
            'request_hash' => str_repeat('f', 64),
            'status' => FiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'FISC-EARLY-DEPARTURE',
            'fiscalized_at' => now(),
            'attempted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.early-departure', $fiscalized), $payload)
            ->assertSessionHasErrors('departure_date');

        $this->assertSame('checked_in', $fiscalized->fresh()->status);
        $this->assertSame(0, $fiscalized->payments()->count());
    }
}
