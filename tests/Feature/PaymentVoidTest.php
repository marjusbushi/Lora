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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Task #166: a voided payment (a refunded / charged-back payment, e.g. a POK
 * card reversal that sets is_voided=true) must NOT count as money paid. Before
 * the fix, every payments.sum('amount') ignored is_voided, so a reversed
 * payment still showed the stay as paid and the hotel silently under-collected.
 */
class PaymentVoidTest extends TestCase
{
    use RefreshDatabase;

    private function stay(float $total = 100): Reservation
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => $total, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => 'checked_in',
            'total_amount' => $total,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    public function test_notvoided_scope_excludes_voided_and_keeps_null(): void
    {
        $res = $this->stay(100);
        $creator = $res->created_by;

        Payment::create(['reservation_id' => $res->id, 'amount' => 60, 'method' => 'cash', 'created_by' => $creator, 'is_voided' => false]);
        Payment::create(['reservation_id' => $res->id, 'amount' => 40, 'method' => 'card', 'created_by' => $creator, 'is_voided' => true]);
        // is_voided is nullable — a NULL row must still count as NOT voided.
        Payment::create(['reservation_id' => $res->id, 'amount' => 10, 'method' => 'cash', 'created_by' => $creator, 'is_voided' => null]);

        // Raw sum sees everything (60 + 40 + 10 = 110); the scope drops only the voided 40.
        $this->assertEquals(110.0, (float) Payment::where('reservation_id', $res->id)->sum('amount'));
        $this->assertEquals(70.0, (float) Payment::where('reservation_id', $res->id)->notVoided()->sum('amount'));
    }

    public function test_voided_payment_does_not_reduce_the_balance_on_show(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $res = $this->stay(100);
        $creator = $res->created_by;

        // Guest paid 60 (real), then a 40 card payment that was later charged back (voided).
        Payment::create(['reservation_id' => $res->id, 'amount' => 60, 'method' => 'cash', 'created_by' => $creator, 'is_voided' => false]);
        Payment::create(['reservation_id' => $res->id, 'amount' => 40, 'method' => 'card', 'created_by' => $creator, 'is_voided' => true]);

        // Paid must be 60 (not 100); the 40 balance is still owed.
        $this->actingAs($admin)->get(route('reservations.show', $res->id))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reservations/Show')
                // JSON serializes whole amounts without a decimal, so assert ints.
                ->where('folio.paid', 60)
                ->where('folio.outstanding', 40));
    }
}
