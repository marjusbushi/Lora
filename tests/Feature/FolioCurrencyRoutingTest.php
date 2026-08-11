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
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foreign-currency folio card/bank/POK money must land on THAT currency's
 * SYSTEM bank account (auto-created "Banka EUR"), never on the base bank and
 * never on a custom same-shape account ("Menaxher"). Payments in the
 * reservation's currency must freeze the reservation's rate, not today's.
 */
class FolioCurrencyRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        Setting::set('pricing.currency', 'EUR');
    }

    private function reservation(): Reservation
    {
        $type = RoomType::firstOrCreate(['name' => 'Std'], ['base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'R'.uniqid(), 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test']);

        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $this->admin->id,
            'check_in_date' => '2026-08-01', 'check_out_date' => '2026-08-03',
            'status' => 'checked_out', 'total_amount' => 200, 'adults' => 2, 'channel' => 'direct',
        ]);
    }

    private function ledgerFor(Payment $payment): FinancePayment
    {
        return FinancePayment::where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->firstOrFail();
    }

    public function test_eur_card_payment_routes_to_auto_created_banka_eur(): void
    {
        $payment = Payment::create([
            'reservation_id' => $this->reservation()->id,
            'amount' => 122.40, 'method' => 'card', 'created_by' => $this->admin->id,
        ]);

        $account = $this->ledgerFor($payment)->account;
        $this->assertSame('Banka EUR', $account->name);
        $this->assertSame('bank', $account->type);
        $this->assertSame('EUR', strtoupper($account->currency));
        $this->assertTrue($account->is_system);
    }

    public function test_custom_same_shape_account_never_absorbs_automatic_money(): void
    {
        // Saturn's "Menaxher": a hand-made account sharing type+currency+scope
        // with the default. Its LOWER id would win under first-match routing.
        $custom = FinanceAccount::create([
            'name' => 'Menaxher', 'type' => 'bank', 'currency' => 'EUR',
            'scope' => 'general', 'is_active' => true, 'is_system' => false,
        ]);

        $payment = Payment::create([
            'reservation_id' => $this->reservation()->id,
            'amount' => 57.60, 'method' => 'card', 'created_by' => $this->admin->id,
        ]);

        $account = $this->ledgerFor($payment)->account;
        $this->assertSame('Banka EUR', $account->name);
        $this->assertNotSame($custom->id, $account->id);
        $this->assertSame(0.0, $custom->balance());
    }

    public function test_base_currency_card_payment_stays_on_banka(): void
    {
        $payment = Payment::create([
            'reservation_id' => $this->reservation()->id,
            'amount' => 5000, 'method' => 'card', 'currency' => 'ALL',
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame('Banka', $this->ledgerFor($payment)->account->name);
    }

    public function test_cash_stays_base_routed_single_drawer(): void
    {
        $payment = Payment::create([
            'reservation_id' => $this->reservation()->id,
            'amount' => 100, 'method' => 'cash', 'created_by' => $this->admin->id,
        ]);

        // EUR cash still lands on the base drawer — the desk runs ONE drawer;
        // POS multicurrency covers per-currency drawer lines.
        $this->assertSame('Arka', $this->ledgerFor($payment)->account->name);
    }

    public function test_payment_freezes_the_reservations_rate_not_todays(): void
    {
        $reservation = $this->reservation();
        $this->assertEqualsWithDelta(93.72, (float) $reservation->exchange_rate, 0.001);

        // The daily rate moves AFTER booking; the folio must not care.
        PlatformSetting::set('currencies.rates', ['ALL' => 99.99], 'json');

        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 200, 'method' => 'card', 'created_by' => $this->admin->id,
        ]);

        $this->assertEqualsWithDelta(93.72, (float) $payment->exchange_rate, 0.001);
        $this->assertEqualsWithDelta(200 * 93.72, (float) $payment->amount_base, 0.01);
        // Paid == owed in base → no phantom-cent difference on a paid-in-full stay.
        $this->assertEqualsWithDelta((float) $reservation->total_amount_base, (float) $payment->amount_base, 0.01);
    }

    public function test_payment_in_another_currency_falls_back_to_todays_snapshot(): void
    {
        $payment = Payment::create([
            'reservation_id' => $this->reservation()->id,
            'amount' => 5000, 'method' => 'cash', 'currency' => 'ALL',
            'created_by' => $this->admin->id,
        ]);

        // Base-currency payment on a EUR reservation: rate is 1, not 93.72.
        $this->assertEqualsWithDelta(1.0, (float) $payment->exchange_rate, 0.000001);
        $this->assertEqualsWithDelta(5000.0, (float) $payment->amount_base, 0.01);
    }
}
