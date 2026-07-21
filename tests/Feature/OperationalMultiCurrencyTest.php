<?php

namespace Tests\Feature;

use App\Models\FinancePayment;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PricingCurrency;
use App\Services\ReservationMoney;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalMultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function configureAllAccountingWithEurPricing(): void
    {
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        Setting::set('pricing.currency', 'EUR');
        Setting::set('financial.fx_all_per_eur', 100, 'number');
    }

    private function reservation(float $amount = 200): Reservation
    {
        $type = RoomType::create(['name' => 'Double', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Euro', 'last_name' => 'Guest']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => '2026-08-01',
            'check_out_date' => '2026-08-03',
            'status' => 'confirmed',
            'total_amount' => $amount,
            'channel' => 'direct',
        ]);
    }

    public function test_reservation_prices_stay_in_eur_and_freeze_the_all_value(): void
    {
        $this->configureAllAccountingWithEurPricing();

        $reservation = $this->reservation();

        $this->assertSame('EUR', PricingCurrency::code());
        $this->assertSame('EUR', $reservation->currency);
        $this->assertSame('100.000000', $reservation->exchange_rate);
        $this->assertSame('20000.00', $reservation->total_amount_base);
    }

    public function test_all_pos_charge_and_eur_payment_reconcile_on_one_folio(): void
    {
        $this->configureAllAccountingWithEurPricing();
        $reservation = $this->reservation();

        $folio = FolioItem::create([
            'reservation_id' => $reservation->id,
            'description' => 'POS Porosi #12',
            'amount' => 1_000,
            'currency' => 'ALL',
            'type' => 'restaurant',
            'charge_date' => '2026-08-01',
        ]);
        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 210,
            'currency' => 'EUR',
            'method' => 'card',
            'created_by' => $reservation->created_by,
        ]);

        $this->assertSame('1000.00', $folio->amount_base);
        $this->assertSame('1.000000', $folio->exchange_rate);
        $this->assertSame([
            'room' => 200.0,
            'charges' => 10.0,
            'discounts' => 0.0,
            'paid' => 210.0,
            'gross' => 210.0,
            'outstanding' => 0.0,
        ], ReservationMoney::totals($reservation->fresh()));
    }

    public function test_finance_ledger_preserves_the_exact_frozen_base_amount(): void
    {
        $this->configureAllAccountingWithEurPricing();
        Setting::set('financial.fx_all_per_eur', 101.2345, 'number');
        $reservation = $this->reservation();

        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 200,
            'currency' => 'EUR',
            'method' => 'card',
            'created_by' => $reservation->created_by,
        ]);
        $ledger = FinancePayment::where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->sole();

        $this->assertSame('20246.90', $payment->amount_base);
        $this->assertSame($payment->amount_base, $ledger->amount_base);
    }
}
