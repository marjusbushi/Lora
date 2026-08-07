<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\PosShift;
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
use Tests\TestCase;

/**
 * Online-collected folio money (method 'ota') accumulates on a per-channel
 * clearing account — never on Arka/Banka — so desk reconciliation, the bank
 * report, and the POS shift drawer stay truthful.
 */
class OtaChannelAccountTest extends TestCase
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

    private function reservation(?string $channel, float $total = 200, ?string $paymentCollect = null): Reservation
    {
        $type = RoomType::firstOrCreate(
            ['name' => 'Std'],
            ['base_price' => 100, 'max_occupancy' => 2, 'amenities' => []],
        );
        $room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => 'R'.uniqid(),
            'floor' => 1,
            'status' => 'available',
        ]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => uniqid().'@t.local', 'phone' => '1']);

        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-03',
            'status' => 'checked_out', 'total_amount' => $total, 'adults' => 2,
            'channel' => $channel, 'payment_collect' => $paymentCollect,
        ]);
    }

    private function ledgerRowFor(Payment $payment): FinancePayment
    {
        return FinancePayment::where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->firstOrFail();
    }

    public function test_ota_payment_creates_and_reuses_the_channel_account(): void
    {
        $first = Payment::create([
            'reservation_id' => $this->reservation('booking.com')->id,
            'amount' => 200, 'method' => 'ota', 'type' => 'payment',
        ]);

        $account = $this->ledgerRowFor($first)->account;
        $this->assertSame('Booking.com', $account->name);
        $this->assertSame('clearing', $account->type);
        $this->assertSame('channel', $account->scope);
        $this->assertSame('EUR', $account->currency);

        // A second booking.com payment reuses the SAME account.
        $second = Payment::create([
            'reservation_id' => $this->reservation('booking.com')->id,
            'amount' => 150, 'method' => 'ota', 'type' => 'payment',
        ]);
        $this->assertSame($account->id, $this->ledgerRowFor($second)->account_id);
        $this->assertSame(1, FinanceAccount::where('scope', 'channel')->count());
        $this->assertEqualsWithDelta(350.0, $account->fresh()->balance(), 0.01);
    }

    public function test_each_channel_gets_its_own_account_and_unknown_falls_back_to_ota(): void
    {
        Payment::create(['reservation_id' => $this->reservation('booking.com')->id, 'amount' => 100, 'method' => 'ota', 'type' => 'payment']);
        Payment::create(['reservation_id' => $this->reservation('airbnb')->id, 'amount' => 100, 'method' => 'ota', 'type' => 'payment']);
        $unknown = Payment::create(['reservation_id' => $this->reservation(null)->id, 'amount' => 100, 'method' => 'ota', 'type' => 'payment']);

        $names = FinanceAccount::where('scope', 'channel')->pluck('name')->sort()->values()->all();
        $this->assertSame(['Airbnb', 'Booking.com', 'OTA'], $names);
        $this->assertSame('OTA', $this->ledgerRowFor($unknown)->account->name);
    }

    public function test_ota_money_never_touches_arka_or_banka(): void
    {
        FinanceAccount::ensureDefaults();
        Payment::create([
            'reservation_id' => $this->reservation('expedia')->id,
            'amount' => 250, 'method' => 'ota', 'type' => 'payment',
        ]);

        $this->assertSame(0.0, FinanceAccount::where('name', 'Arka')->firstOrFail()->balance());
        $this->assertSame(0.0, FinanceAccount::where('name', 'Banka')->firstOrFail()->balance());
        $this->assertEqualsWithDelta(250.0, FinanceAccount::where('name', 'Expedia')->firstOrFail()->balance(), 0.01);
    }

    public function test_import_routing_ignores_channel_accounts(): void
    {
        // Channel accounts exist first; a Beds24-style general clearing account too.
        Payment::create(['reservation_id' => $this->reservation('booking.com')->id, 'amount' => 100, 'method' => 'ota', 'type' => 'payment']);
        $beds24 = FinanceAccount::create([
            'name' => 'Beds24', 'type' => 'clearing', 'scope' => 'general',
            'currency' => 'EUR', 'is_active' => true,
        ]);

        $this->assertSame($beds24->id, FinanceLedger::accountFor('import')->id);
    }

    public function test_bank_report_and_pos_shift_ignore_ota_payments(): void
    {
        $shift = PosShift::create([
            'user_id' => $this->admin->id, 'status' => 'open',
            'opening_float' => 50, 'opened_at' => now(),
        ]);
        Payment::create([
            'reservation_id' => $this->reservation('booking.com')->id,
            'amount' => 300, 'method' => 'ota', 'type' => 'payment',
        ]);

        // The drawer expects exactly the float — folio OTA money is invisible to it.
        $this->assertSame(50.0, $shift->fresh()->liveExpectedCash());

        // And no ledger row of a channel account is bank-typed.
        $this->assertSame(
            0,
            FinancePayment::whereHas('account', fn ($q) => $q->where('scope', 'channel')->where('type', '!=', 'clearing'))->count(),
        );
        $this->assertSame(0, FinancePayment::whereHas('account', fn ($q) => $q->where('type', 'bank'))->count());
    }
}
