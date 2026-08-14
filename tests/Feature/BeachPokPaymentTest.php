<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BeachPokPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function configurePok(): void
    {
        config()->set('services.pok.merchant_id', 'M-1');
        config()->set('services.pok.key_id', 'kid');
        config()->set('services.pok.key_secret', 'ksecret');
        config()->set('services.pok.production', false);
        config()->set('services.pok.base_url', 'https://api-staging.pokpay.io');
    }

    /** Fake i 3 thirrjeve POK: login, create (POST .../sdk-orders), retrieve (GET .../sdk-orders/{id}). */
    private function fakePok(array $orderStatus): void
    {
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => $orderStatus]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_b1', 'finalAmount' => 500, 'currencyCode' => 'EUR']]], 200),
        ]);
    }

    private function reservation(float $total = 500, array $overrides = []): BeachReservation
    {
        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => $total]);
        $unit = $zone->units()->create(['number' => '1']);

        return BeachReservation::create(array_merge([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Guest Test',
            'guest_phone' => '069123',
            'start_date' => today()->addDay()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'status' => BeachReservation::STATUS_PENDING,
            'source' => BeachReservation::SOURCE_WEBSITE,
            'total_amount' => $total,
            'confirmation_token' => str_repeat('a', 40),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function paidStatus(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ord_b1', 'isCompleted' => true, 'isCanceled' => false,
            'isRefunded' => false, 'finalAmount' => 500, 'currencyCode' => 'EUR',
        ], $overrides);
    }

    public function test_order_is_created_server_side_with_db_amount(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['isCompleted' => false])); // ende e papaguar
        $reservation = $this->reservation(500);

        $this->get(route('website.beach.pay', $reservation->confirmation_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Website/BookSunbedsPayment')
                ->where('orderId', 'ord_b1')
                ->where('amount', 500));

        // Shuma e order-it vjen VETËM nga DB — kurrë nga klienti (rruga pranon vetëm token).
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/sdk-orders')
            && (float) $request['amount'] === 500.0);
        $this->assertSame('ord_b1', $reservation->fresh()->pok_order_id);
    }

    public function test_wrong_amount_from_pok_is_refused(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['finalAmount' => 400])); // R2: shumë e manipuluar
        $reservation = $this->reservation(500, ['pok_order_id' => 'ord_b1']);

        $this->post(route('website.beach.pay.confirm', $reservation->confirmation_token));

        $this->assertNull($reservation->fresh()->paid_at);
        $this->assertSame(BeachReservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_confirm_settles_paid_order_and_is_idempotent(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus());
        $reservation = $this->reservation(500, ['pok_order_id' => 'ord_b1']);

        $this->post(route('website.beach.pay.confirm', $reservation->confirmation_token))
            ->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));

        $fresh = $reservation->fresh();
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(BeachReservation::STATUS_CONFIRMED, $fresh->status);

        // Ripagesa/ri-confirm: flip-i atomik prek 0 rreshta — paid_at s'ndryshon.
        $paidAt = $fresh->paid_at->toDateTimeString();
        $this->post(route('website.beach.pay.confirm', $reservation->confirmation_token))
            ->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));
        $this->assertSame($paidAt, $reservation->fresh()->paid_at->toDateTimeString());

        // Edhe faqja e pagesës pas pagese → gjithmonë kthim te konfirmimi (guard ripagese).
        $this->get(route('website.beach.pay', $reservation->confirmation_token))
            ->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));
    }

    public function test_confirmation_offers_online_payment_only_when_unpaid(): void
    {
        $this->configurePok();
        $reservation = $this->reservation(500);

        $this->get(route('website.beach.confirmation', $reservation->confirmation_token))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reservation.pok_enabled', true)
                ->where('reservation.paid_at', null));

        $reservation->update(['paid_at' => now(), 'status' => BeachReservation::STATUS_CONFIRMED]);

        $this->get(route('website.beach.confirmation', $reservation->confirmation_token))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reservation.pok_enabled', false)
                ->whereNot('reservation.paid_at', null));
    }

    public function test_pay_routes_carry_module_and_throttle(): void
    {
        $this->assertContains('module:beach', Route::getRoutes()->getByName('website.beach.pay')->middleware());
        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('website.beach.pay')->middleware());
        $this->assertContains('module:beach', Route::getRoutes()->getByName('website.beach.pay.confirm')->middleware());
        $this->assertContains('throttle:20,1', Route::getRoutes()->getByName('website.beach.pay.confirm')->middleware());
    }
}
