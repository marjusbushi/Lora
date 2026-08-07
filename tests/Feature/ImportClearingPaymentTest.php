<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinanceLedger;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Migration settlements (method 'import') must land on a CLEARING account —
 * never Arka or a real bank — so cash reconciliation and the bank report stay
 * truthful while historical reservations stop showing as unpaid.
 */
class ImportClearingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // Saturn-like: accounting in Lek, selling in EUR.
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        Setting::set('pricing.currency', 'EUR');
    }

    private function beds24Account(): FinanceAccount
    {
        return FinanceAccount::create([
            'name' => 'Beds24', 'type' => 'clearing', 'currency' => 'EUR',
            'scope' => 'general', 'is_active' => true,
        ]);
    }

    private function checkedOutReservation(float $total = 200): Reservation
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@t.local', 'phone' => '1']);

        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-03',
            'status' => 'checked_out', 'total_amount' => $total, 'adults' => 2,
        ]);
    }

    public function test_account_for_import_prefers_the_hotel_created_clearing_account(): void
    {
        $beds24 = $this->beds24Account();

        $this->assertSame($beds24->id, FinanceLedger::accountFor('import')->id);
        $this->assertSame(1, FinanceAccount::where('type', 'clearing')->count(), 'no duplicate clearing account created');
    }

    public function test_account_for_import_creates_a_generic_clearing_account_when_none_exists(): void
    {
        $account = FinanceLedger::accountFor('import');

        $this->assertSame('clearing', $account->type);
        $this->assertSame('Import', $account->name);
        // Idempotent: a second call reuses it.
        $this->assertSame($account->id, FinanceLedger::accountFor('import')->id);
        $this->assertSame(1, FinanceAccount::where('type', 'clearing')->count());
    }

    public function test_import_payment_lands_on_clearing_and_clears_outstanding_without_touching_real_accounts(): void
    {
        $beds24 = $this->beds24Account();
        $reservation = $this->checkedOutReservation(200);

        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 200,
            'method' => 'import',
            'type' => 'payment',
        ]);

        $ledger = FinancePayment::where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->firstOrFail();
        $this->assertSame($beds24->id, $ledger->account_id, 'import settlement must land on the clearing account');
        $this->assertSame('import', $ledger->method);
        $this->assertSame('in', $ledger->direction);

        // The real money accounts stay untouched.
        $this->assertSame(0.0, FinanceAccount::where('name', 'Arka')->firstOrFail()->balance());
        $this->assertSame(0.0, FinanceAccount::where('name', 'Banka')->firstOrFail()->balance());
        // Clearing balance carries the settlement (EUR account sums own-currency amounts).
        $this->assertEqualsWithDelta(200.0, $beds24->balance(), 0.01);
        // The folio is settled: payments cover the room charge.
        $this->assertEqualsWithDelta(
            (float) $reservation->total_amount,
            (float) Payment::where('reservation_id', $reservation->id)->notVoided()->sum('amount'),
            0.01,
        );
    }

    public function test_bank_report_excludes_the_clearing_account_and_its_rows(): void
    {
        $this->beds24Account();
        $reservation = $this->checkedOutReservation(300);
        // One synthetic settlement + one real card payment (routes to Banka).
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 200, 'method' => 'import', 'type' => 'payment']);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 100, 'method' => 'card', 'type' => 'payment']);

        $date = now()->toDateString();
        $this->actingAs($this->admin)
            ->get(route('reports.bankPayments', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/BankPayments')
                ->has('rows', 1)
                ->where('rows.0.method', 'card')
                ->missing('rows.0.account_is_clearing') // structural: row belongs to a bank account
            );

        // And no report row references the clearing account at all.
        $this->assertSame(
            0,
            FinancePayment::whereHas('account', fn ($q) => $q->where('type', 'clearing'))
                ->where('method', 'card')
                ->count(),
        );
    }
}
