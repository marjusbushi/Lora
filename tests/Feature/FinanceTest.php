<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(): Reservation
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'email' => uniqid().'@t.local', 'phone' => '1']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => '2026-08-01',
            'check_out_date' => '2026-08-03',
            'status' => 'confirmed',
        ]);
    }

    private function role(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function arka(): FinanceAccount
    {
        FinanceAccount::ensureDefaults();

        return FinanceAccount::where('type', 'cash')->firstOrFail();
    }

    // -- auto-feed --------------------------------------------------------

    public function test_cash_folio_payment_lands_in_arka_once(): void
    {
        $res = $this->reservation();
        $payment = Payment::create(['reservation_id' => $res->id, 'amount' => 120, 'method' => 'cash', 'type' => 'payment']);

        $this->assertSame(120.0, $this->arka()->balance());
        $this->assertDatabaseCount('finance_payments', 1);

        // re-saving the SAME folio payment must not double-count
        $payment->touch();
        $payment->save();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame(120.0, $this->arka()->balance());
    }

    public function test_card_folio_payment_lands_in_bank_not_arka(): void
    {
        $res = $this->reservation();
        Payment::create(['reservation_id' => $res->id, 'amount' => 200, 'method' => 'card', 'type' => 'payment']);

        FinanceAccount::ensureDefaults();
        $bank = FinanceAccount::where('type', 'bank')->firstOrFail();
        $this->assertSame(200.0, $bank->balance());
        $this->assertSame(0.0, $this->arka()->balance());
    }

    public function test_voiding_a_folio_payment_reverses_the_ledger(): void
    {
        $res = $this->reservation();
        $payment = Payment::create(['reservation_id' => $res->id, 'amount' => 90, 'method' => 'cash', 'type' => 'payment']);
        $this->assertSame(90.0, $this->arka()->balance());

        $payment->update(['is_voided' => true]);

        $this->assertSame(0.0, $this->arka()->balance());
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_deposits_and_refunds_are_mirrored_with_the_correct_direction(): void
    {
        $res = $this->reservation();
        Payment::create(['reservation_id' => $res->id, 'amount' => 100, 'method' => 'cash', 'type' => 'deposit']);
        Payment::create(['reservation_id' => $res->id, 'amount' => 30, 'method' => 'cash', 'type' => 'refund']);

        $this->assertSame(70.0, $this->arka()->balance());
        $this->assertSame(1, FinancePayment::where('direction', 'in')->count());
        $this->assertSame(1, FinancePayment::where('direction', 'out')->count());
    }

    public function test_closing_a_pos_shift_deposits_the_counted_yield_once(): void
    {
        $user = User::factory()->create();
        $shift = PosShift::create([
            'user_id' => $user->id, 'status' => 'open', 'opening_float' => 50,
            'opened_at' => now()->subHours(8),
        ]);
        $this->assertDatabaseCount('finance_payments', 0);

        $shift->update([
            'status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id,
            'counted_cash' => 236.50, 'cash_sales' => 190, 'over_short' => -3.5,
        ]);

        $this->assertSame(186.5, $this->arka()->balance()); // 236.50 − 50 float
        $this->assertDatabaseCount('finance_payments', 1);

        $shift->touch();
        $shift->save();
        $this->assertDatabaseCount('finance_payments', 1); // idempotent
    }

    public function test_backfill_is_idempotent(): void
    {
        $res = $this->reservation();
        Payment::create(['reservation_id' => $res->id, 'amount' => 75, 'method' => 'cash', 'type' => 'payment']);
        FinancePayment::query()->delete(); // simulate history from before the module

        $this->artisan('finance:backfill', ['--from' => '2026-01-01'])->assertSuccessful();
        $this->assertDatabaseCount('finance_payments', 1);

        $this->artisan('finance:backfill', ['--from' => '2026-01-01'])->assertSuccessful();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame(75.0, $this->arka()->balance());
    }

    // -- currency invariants ------------------------------------------------

    public function test_lek_payment_computes_amount_base_and_requires_fx(): void
    {
        $arka = $this->arka();

        $p = FinancePayment::create([
            'direction' => 'out', 'account_id' => $arka->id, 'amount' => 9870,
            'currency' => 'ALL', 'fx_rate' => 98.7, 'method' => 'cash',
            'source' => 'manual', 'description' => 'blerje', 'paid_at' => now(),
        ]);
        $this->assertSame(100.0, (float) $p->amount_base);

        $this->expectException(\InvalidArgumentException::class);
        FinancePayment::create([
            'direction' => 'out', 'account_id' => $arka->id, 'amount' => 500,
            'currency' => 'ALL', 'method' => 'cash', 'source' => 'manual',
            'description' => 'pa kurs', 'paid_at' => now(),
        ]);
    }

    // -- transfers ------------------------------------------------------------

    public function test_transfer_is_one_row_and_moves_both_balances(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->first();
        $bank = FinanceAccount::where('type', 'bank')->first();
        $res = $this->reservation();
        Payment::create(['reservation_id' => $res->id, 'amount' => 1000, 'method' => 'cash', 'type' => 'payment']);

        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $arka->id, 'to_account_id' => $bank->id, 'amount' => 600,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(400.0, $arka->balance());
        $this->assertSame(600.0, $bank->balance());
        $this->assertSame(1, FinancePayment::where('direction', 'transfer')->count());
    }

    public function test_cross_currency_transfer_uses_the_applied_rate_for_the_receiving_side(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->first();
        $lek = FinanceAccount::create(['name' => 'Banka Lek', 'type' => 'bank', 'currency' => 'ALL', 'is_active' => true]);
        $res = $this->reservation();
        Payment::create(['reservation_id' => $res->id, 'amount' => 1000, 'method' => 'cash', 'type' => 'payment']);

        // Cross-currency without a rate → refused, nothing written.
        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $arka->id, 'to_account_id' => $lek->id, 'amount' => 100,
        ])->assertSessionHasErrors(['exchange_rate']);
        $this->assertSame(0, FinancePayment::where('direction', 'transfer')->count());

        // The desk applies the bank's REAL rate (editable via the pen): 1 EUR = 92.50 ALL.
        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $arka->id, 'to_account_id' => $lek->id, 'amount' => 100, 'exchange_rate' => 92.5,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $row = FinancePayment::where('direction', 'transfer')->sole();
        $this->assertSame(9250.0, (float) $row->counter_amount);
        $this->assertStringContainsString('1 EUR = 92.5 ALL', $row->description);

        // Each side moves in its OWN currency: −100 EUR, +9,250 L.
        $this->assertSame(900.0, $arka->balance());
        $this->assertSame(9250.0, $lek->balance());
    }

    // -- permissions ------------------------------------------------------------

    public function test_receptionist_is_locked_out_of_the_finance_module_entirely(): void
    {
        // Renato (2026-08-18, after impersonating the role live): the desk
        // does not see the Financa module AT ALL — view_finance left the role.
        // Checkout money still flows through the reservation folio
        // (reservations.payment, gated by update_reservations) — proven in
        // ReservationPaymentTest.
        $rec = $this->role('receptionist');
        $arka = $this->arka();

        $this->actingAs($rec)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($rec)->get(route('finance.accounts'))->assertForbidden();
        $this->actingAs($rec)->get(route('finance.payments'))->assertForbidden();

        $this->actingAs($rec)->post(route('finance.payments.store'), [
            'direction' => 'in', 'account_id' => $arka->id, 'amount' => 50,
            'currency' => 'EUR', 'method' => 'cash', 'description' => 'arkëtim i bllokuar',
        ])->assertForbidden();
        $this->assertSame(0.0, $arka->balance());

        $this->actingAs($rec)->post(route('finance.transfers.store'), [
            'from_account_id' => $arka->id, 'to_account_id' => $arka->id + 1, 'amount' => 5,
        ])->assertForbidden();
    }

    public function test_user_without_view_finance_gets_403_and_manager_never_sees_bank(): void
    {
        $this->withoutVite();
        $housekeeping = $this->role('housekeeping');
        $this->actingAs($housekeeping)->get(route('finance.index'))->assertForbidden();

        // The bank wall: the manager holds view_finance but not
        // view_bank_accounts — bank stays owner-only (Lejet v2).
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        FinanceAccount::ensureDefaults();
        $bank = FinanceAccount::where('type', 'bank')->first();

        // page props must not contain the bank account
        $this->actingAs($manager)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', fn ($accounts) => collect($accounts)->every(fn ($a) => $a['type'] === 'cash')));

        // and a hand-typed bank account_id on the ledger is refused
        $this->actingAs($manager)->get(route('finance.accounts', ['account_id' => $bank->id]))->assertForbidden();
    }

    public function test_dashboard_math_matches_the_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00'));
        $this->withoutVite();
        $admin = $this->role('admin');
        $res = $this->reservation();
        Payment::create(['reservation_id' => $res->id, 'amount' => 300, 'method' => 'cash', 'type' => 'payment']);
        Payment::create(['reservation_id' => $res->id, 'amount' => 150, 'method' => 'card', 'type' => 'payment']);

        $arka = FinanceAccount::where('type', 'cash')->firstOrFail();
        $bank = FinanceAccount::where('type', 'bank')->firstOrFail();
        FinancePayment::create([
            'direction' => 'out', 'account_id' => $arka->id, 'amount' => 50,
            'currency' => 'EUR', 'method' => 'cash', 'source' => 'manual',
            'description' => 'Shpenzim testi', 'paid_at' => now(),
        ]);
        FinancePayment::create([
            'direction' => 'transfer', 'account_id' => $arka->id, 'counter_account_id' => $bank->id,
            'amount' => 100, 'currency' => 'EUR', 'method' => 'bank', 'source' => 'manual',
            'description' => 'Transfer test', 'paid_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('finance.index', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Index')
                ->where('accounts.0.balance', 150)
                ->where('accounts.1.balance', 250)
                ->where('summary.period', 'month')
                ->where('summary.income', 450)
                ->where('summary.expenses', 50)
                ->where('summary.net', 400)
                ->has('cashflow', 14)
                ->has('latest'));
    }
}
