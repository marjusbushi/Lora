<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeachReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BeachUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => 800]);
        $this->unit = $zone->units()->create(['number' => '14']);
    }

    private function reserve(string $start, string $end, string $status = BeachReservation::STATUS_CONFIRMED): BeachReservation
    {
        return BeachReservation::create([
            'beach_unit_id' => $this->unit->id,
            'guest_name' => 'Ekzistues', 'guest_phone' => '069',
            'start_date' => $start, 'end_date' => $end,
            'status' => $status, 'source' => BeachReservation::SOURCE_RECEPTION,
        ]);
    }

    public function test_overlap_is_inclusive_on_day_boundaries(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();
        $this->reserve($d(15), $d(17));

        // Dita e fundit (17) përplaset me fillimin 17 — ditë plazhi, jo netë.
        $this->assertFalse(BeachReservation::isUnitAvailable($this->unit->id, $d(17), $d(19)));
        $this->assertFalse(BeachReservation::isUnitAvailable($this->unit->id, $d(13), $d(15)));
        $this->assertTrue(BeachReservation::isUnitAvailable($this->unit->id, $d(18), $d(19)));
        $this->assertTrue(BeachReservation::isUnitAvailable($this->unit->id, $d(13), $d(14)));
    }

    public function test_cancelled_reservation_does_not_block(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();
        $this->reserve($d(15), $d(17), BeachReservation::STATUS_CANCELLED);

        $this->assertTrue(BeachReservation::isUnitAvailable($this->unit->id, $d(15), $d(17)));
    }

    public function test_store_conflict_returns_422(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();
        $this->reserve($d(15), $d(17));

        $this->actingAs($this->admin)->postJson(route('beach.reservations.store'), [
            'beach_unit_id' => $this->unit->id,
            'start_date' => $d(16), 'end_date' => $d(18),
            'guest_name' => 'Tjetri', 'guest_phone' => '068',
        ])->assertStatus(422);

        $this->assertSame(1, BeachReservation::count());
    }

    public function test_update_with_exclude_id_does_not_conflict_with_itself(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();
        $reservation = $this->reserve($d(15), $d(17));

        // Zgjatja e të njëjtit rezervim — s'përplaset me veten.
        $this->actingAs($this->admin)
            ->put(route('beach.reservations.update', $reservation), [
                'start_date' => $d(15), 'end_date' => $d(19),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($d(19), $reservation->fresh()->end_date->toDateString());

        // Por përplasja reale me një rezervim tjetër → 422.
        $other = $this->reserve($d(21), $d(22));
        $this->actingAs($this->admin)->putJson(route('beach.reservations.update', $reservation), [
            'end_date' => $d(21),
        ])->assertStatus(422);
    }

    public function test_total_amount_is_server_side_and_ignores_client_value(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();

        $this->actingAs($this->admin)->post(route('beach.reservations.store'), [
            'beach_unit_id' => $this->unit->id,
            'start_date' => $d(10), 'end_date' => $d(14), // 5 ditë inkluzive
            'guest_name' => 'Klienti', 'guest_phone' => '067',
            'total_amount' => 1, // injorohet
        ])->assertSessionHasNoErrors();

        $this->assertSame('4000.00', BeachReservation::sole()->total_amount);
    }

    private function openShiftFor(\App\Models\User $user): \App\Models\BeachShift
    {
        return \App\Models\BeachShift::create([
            'user_id' => $user->id, 'status' => 'open',
            'opening_float' => 0, 'opened_at' => now(),
        ]);
    }

    public function test_mark_paid_guards(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();
        $reservation = $this->reserve($d(1), $d(2));

        // Pa turn të hapur → 422 (si POS-i).
        $this->actingAs($this->admin)
            ->postJson(route('beach.reservations.mark-paid', $reservation), ['method' => 'cash'])
            ->assertStatus(422);

        $shift = $this->openShiftFor($this->admin);

        // Shënohet një herë…
        $this->actingAs($this->admin)
            ->post(route('beach.reservations.mark-paid', $reservation), ['method' => 'cash'])
            ->assertSessionHasNoErrors();
        $fresh = $reservation->fresh();
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('cash', $fresh->payment_method);
        $this->assertSame($shift->id, $fresh->beach_shift_id);

        // …dy herë jo (flip atomik 0 rreshta → 422).
        $this->actingAs($this->admin)
            ->postJson(route('beach.reservations.mark-paid', $reservation), ['method' => 'card'])
            ->assertStatus(422);
        $this->assertSame('cash', $reservation->fresh()->payment_method);

        // Heqja lejohet për cash/kartë…
        $this->actingAs($this->admin)
            ->post(route('beach.reservations.unmark-paid', $reservation))
            ->assertSessionHasNoErrors();
        $this->assertNull($reservation->fresh()->paid_at);

        // …por kurrë për pagesën online (POK).
        $reservation->update(['paid_at' => now(), 'payment_method' => 'online']);
        $this->actingAs($this->admin)
            ->postJson(route('beach.reservations.unmark-paid', $reservation))
            ->assertStatus(422);
        $this->assertNotNull($reservation->fresh()->paid_at);
    }

    public function test_calendar_props_carry_payment_state_and_routes_are_gated(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();
        $paid = $this->reserve(today()->toDateString(), $d(1));
        $paid->update(['paid_at' => now(), 'payment_method' => 'cash']);

        // Kalendari i jep UI-së gjendjen e pagesës (shenja € + numëruesi ndërtohen prej saj).
        $this->actingAs($this->admin)
            ->get(route('beach.calendar'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Beach/Calendar')
                ->where('reservations.0.payment_method', 'cash')
                ->whereNot('reservations.0.paid_at', null));

        // Rrugët e pagesës nën permission update_beach.
        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        $this->assertContains('permission:update_beach', $routes->getByName('beach.reservations.mark-paid')->middleware());
        $this->assertContains('permission:update_beach', $routes->getByName('beach.reservations.unmark-paid')->middleware());

        // Pa leje beach → 403 edhe në mark-paid.
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $housekeeper = \App\Models\User::factory()->create();
        $housekeeper->assignRole('housekeeping');
        $this->actingAs($housekeeper)
            ->post(route('beach.reservations.mark-paid', $paid), ['method' => 'cash'])
            ->assertForbidden();
    }

    public function test_reception_books_beyond_public_window(): void
    {
        $d = fn (int $offset) => today()->addDays($offset)->toDateString();

        $this->actingAs($this->admin)->post(route('beach.reservations.store'), [
            'beach_unit_id' => $this->unit->id,
            'start_date' => $d(60), 'end_date' => $d(62),
            'guest_name' => 'Larg', 'guest_phone' => '066',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, BeachReservation::count());
    }
}
