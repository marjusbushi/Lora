<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule: a guest must not be able to check out with money still owed.
 * The guard lives in ReservationController::checkOut so NO UI path (list,
 * calendar, or detail) can bypass it — the list + calendar both POST an empty
 * body, which previously checked the guest out unpaid.
 */
class CheckoutRequiresPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin'); // has update_reservations

        return $user;
    }

    private function checkedInStay(float $total = 100): Reservation
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => $total, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'status' => 'checked_in',
            'total_amount' => $total,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    public function test_checkout_is_blocked_when_the_guest_still_owes(): void
    {
        $res = $this->checkedInStay(100); // no payments → owes 100

        $this->actingAs($this->staff())
            ->post(route('reservations.check-out', $res->id), []) // empty body = the list/calendar path
            ->assertSessionHasErrors('settle_method');

        // Guest stays IN — not checked out, and no phantom payment was recorded.
        $this->assertSame('checked_in', $res->fresh()->status);
        $this->assertSame(0, $res->payments()->count());
    }

    public function test_checkout_with_settle_method_records_payment_then_checks_out(): void
    {
        $res = $this->checkedInStay(100);

        $this->actingAs($this->staff())
            ->post(route('reservations.check-out', $res->id), ['settle_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $res->refresh();
        $this->assertSame('checked_out', $res->status);
        $this->assertSame(1, $res->payments()->count());
        $this->assertEqualsWithDelta(100.0, (float) $res->payments()->sum('amount'), 0.001);
        $this->assertSame('cleaning', $res->room->fresh()->status);
    }

    public function test_checkout_succeeds_with_no_settle_when_balance_is_already_zero(): void
    {
        $res = $this->checkedInStay(100);
        // Fully prepaid (e.g. an OTA stay whose payment was recorded on import) → balance 0.
        Payment::create([
            'reservation_id' => $res->id, 'amount' => 100, 'method' => 'card',
            'created_by' => $res->created_by, 'is_voided' => false,
        ]);

        $this->actingAs($this->staff())
            ->post(route('reservations.check-out', $res->id), [])
            ->assertSessionHasNoErrors();

        $this->assertSame('checked_out', $res->fresh()->status);
        // No extra payment was created — it was already settled.
        $this->assertSame(1, $res->payments()->count());
    }
}
