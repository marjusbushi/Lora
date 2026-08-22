<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachShift;
use App\Models\BeachZone;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Setting;
use App\Models\User;
use App\Services\FinanceLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeachShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function paidReservation(float $total, string $method): BeachReservation
    {
        $zone = BeachZone::create(['name' => 'Zona '.uniqid(), 'price_per_day' => $total]);
        $unit = $zone->units()->create(['number' => (string) random_int(10000, 99999)]);
        $reservation = BeachReservation::create([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Guest Shift', 'guest_phone' => '069',
            'start_date' => today()->toDateString(),
            'end_date' => today()->toDateString(),
            'status' => BeachReservation::STATUS_CONFIRMED,
            'source' => BeachReservation::SOURCE_RECEPTION,
            'total_amount' => $total,
        ]);

        $this->actingAs($this->admin)
            ->post(route('beach.reservations.mark-paid', $reservation), ['method' => $method])
            ->assertSessionHasNoErrors();

        return $reservation->fresh();
    }

    public function test_full_shift_cycle_expected_cash_and_variance_to_finance(): void
    {
        // Hapja me float 100.
        $this->actingAs($this->admin)
            ->post(route('beach.shifts.open'), ['opening_float' => 100])
            ->assertSessionHasNoErrors();
        $shift = BeachShift::currentFor($this->admin->id);
        $this->assertNotNull($shift);

        // 500 cash + 300 kartë gjatë turnit — vetëm cash-i pritet në sirtar.
        $cash = $this->paidReservation(500, 'cash');
        $card = $this->paidReservation(300, 'card');
        $this->assertSame($shift->id, $cash->beach_shift_id);
        $this->assertSame($shift->id, $card->beach_shift_id);

        // Mbyllja me 590 të numëruara → expected 600 (100+500), diferenca -10.
        $this->actingAs($this->admin)
            ->post(route('beach.shifts.close', $shift), ['counted_cash' => 590])
            ->assertSessionHasNoErrors();

        $closed = $shift->fresh();
        $this->assertSame('closed', $closed->status);
        $this->assertSame('600.00', $closed->expected_cash);
        $this->assertSame('500.00', $closed->cash_sales);
        $this->assertSame('300.00', $closed->card_sales);
        $this->assertSame('-10.00', $closed->over_short);

        // Renato (2026-08-21): diferenca mbetet VETËM te raporti i turnit —
        // asnjë rresht automatik në Financë; menaxheri vendos vetë.
        $this->assertSame(0, FinancePayment::where('sourceable_type', BeachShift::class)
            ->where('sourceable_id', $shift->id)->count());

        // Pas mbylljes: heqja e shënimit të pagesës refuzohet (Z-raport i ngrirë).
        $this->actingAs($this->admin)
            ->postJson(route('beach.reservations.unmark-paid', $cash), [])
            ->assertStatus(422);
    }

    public function test_variance_never_reaches_the_accounts_even_in_split_mode(): void
    {
        // Renato (2026-08-21): report-only — edhe në split mode, mbyllja me
        // tepricë +25 nuk krijon asnjë rresht; vlera rri te turni.
        Setting::set('finance.beach_account_mode', FinanceLedger::POS_MODE_SPLIT_CASH);

        $this->actingAs($this->admin)->post(route('beach.shifts.open'), ['opening_float' => 0]);
        $shift = BeachShift::currentFor($this->admin->id);
        $this->actingAs($this->admin)->post(route('beach.shifts.close', $shift), ['counted_cash' => 25]);

        $this->assertSame('25.00', $shift->fresh()->over_short);
        $this->assertSame(0, FinancePayment::where('sourceable_type', BeachShift::class)
            ->where('sourceable_id', $shift->id)->count());

        // Rihapja mbetet e pastër gjithashtu.
        $shift->fresh()->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        $this->assertSame(0, FinancePayment::where('sourceable_type', BeachShift::class)
            ->where('sourceable_id', $shift->id)->count());
    }

    public function test_double_open_is_refused_and_permissions_enforced(): void
    {
        $this->actingAs($this->admin)->post(route('beach.shifts.open'), ['opening_float' => 0]);
        $this->assertSame(1, BeachShift::where('user_id', $this->admin->id)->where('status', 'open')->count());

        // Hapja e dytë → refuzohet me error flash, mbetet NJË turn i hapur.
        $this->actingAs($this->admin)->post(route('beach.shifts.open'), ['opening_float' => 50])
            ->assertSessionHas('error');
        $this->assertSame(1, BeachShift::where('user_id', $this->admin->id)->where('status', 'open')->count());

        // Pa leje beach-shift → 403 (housekeeping s'ka open_beach_shift).
        $housekeeper = User::factory()->create();
        $housekeeper->assignRole('housekeeping');
        $this->actingAs($housekeeper)->post(route('beach.shifts.open'), ['opening_float' => 0])
            ->assertForbidden();

        // Recepsionisti s'mbyll dot turnin e tjetrit (s'ka close_any_beach_shift).
        $receptionist = User::factory()->create();
        $receptionist->assignRole('receptionist');
        $shift = BeachShift::currentFor($this->admin->id);
        $this->actingAs($receptionist)->post(route('beach.shifts.close', $shift), ['counted_cash' => 0])
            ->assertForbidden();
    }

    public function test_double_close_is_refused_and_frozen_snapshot_survives(): void
    {
        $this->actingAs($this->admin)->post(route('beach.shifts.open'), ['opening_float' => 100]);
        $shift = BeachShift::currentFor($this->admin->id);

        $this->actingAs($this->admin)->post(route('beach.shifts.close', $shift), ['counted_cash' => 100])
            ->assertSessionHasNoErrors();

        // Mbyllja e dytë (dy tab-e / klikim i dyfishtë) → refuzohet, Z-raporti i ngrirë s'preket.
        $this->actingAs($this->admin)->post(route('beach.shifts.close', $shift), ['counted_cash' => 999])
            ->assertSessionHas('error');
        $this->assertSame('100.00', $shift->fresh()->counted_cash);

        // Pas mbylljes s'ka turn të hapur → mark-paid refuzohet (serializimi me lock e garanton
        // edhe në MySQL kur të dyja ndodhin njëkohësisht — pagesa ose hyn para snapshot-it, ose 422).
        $zone = BeachZone::create(['name' => 'Zona Pas Mbylljes', 'price_per_day' => 100]);
        $unit = $zone->units()->create(['number' => '88888']);
        $reservation = BeachReservation::create([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Pas Mbylljes', 'guest_phone' => '069',
            'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'status' => BeachReservation::STATUS_CONFIRMED,
            'source' => BeachReservation::SOURCE_RECEPTION,
            'total_amount' => 100,
        ]);
        $this->actingAs($this->admin)
            ->postJson(route('beach.reservations.mark-paid', $reservation), ['method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_online_payment_never_enters_expected_cash(): void
    {
        $this->actingAs($this->admin)->post(route('beach.shifts.open'), ['opening_float' => 100]);
        $shift = BeachShift::currentFor($this->admin->id);

        // Pagesë online (POK) — pa turn, pa sirtar.
        $zone = BeachZone::create(['name' => 'Zona Online', 'price_per_day' => 700]);
        $unit = $zone->units()->create(['number' => '77777']);
        BeachReservation::create([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Online Guest', 'guest_phone' => '069',
            'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'status' => BeachReservation::STATUS_CONFIRMED,
            'source' => BeachReservation::SOURCE_WEBSITE,
            'total_amount' => 700,
            'paid_at' => now(), 'payment_method' => 'online',
        ]);

        $this->assertSame(100.0, $shift->liveExpectedCash());
    }
}
