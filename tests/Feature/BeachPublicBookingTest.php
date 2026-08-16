<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BeachPublicBookingTest extends TestCase
{
    use RefreshDatabase;

    private BeachUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => 800]);
        $this->unit = $zone->units()->create(['number' => '1']);
        Setting::set('beach.booking_window_days', 10, 'number');
    }

    /** @return array<string, mixed> */
    private function payload(string $start, string $end): array
    {
        return [
            'beach_unit_id' => $this->unit->id,
            'start_date' => $start, 'end_date' => $end,
            'guest_name' => 'Guest Test', 'guest_phone' => '069123',
        ];
    }

    public function test_window_boundary_is_inclusive(): void
    {
        $edge = today()->addDays(10)->toDateString();
        $past = today()->addDays(11)->toDateString();

        // Dita e 10-të (kufiri) lejohet.
        $this->getJson(route('website.beach.availability', ['start_date' => $edge, 'end_date' => $edge]))
            ->assertOk();
        $this->post(route('website.beach.submit'), $this->payload($edge, $edge))
            ->assertSessionHasNoErrors();

        // Dita e 11-të refuzohet — availability DHE submit.
        $this->getJson(route('website.beach.availability', ['start_date' => $past, 'end_date' => $past]))
            ->assertStatus(422);
        $this->postJson(route('website.beach.submit'), $this->payload($past, $past))
            ->assertStatus(422);

        // Data në të shkuarën refuzohet.
        $this->postJson(route('website.beach.submit'), $this->payload(
            today()->subDay()->toDateString(),
            today()->toDateString(),
        ))->assertStatus(422);

        $this->assertSame(1, BeachReservation::count());
    }

    public function test_season_is_enforced_when_set(): void
    {
        Setting::set('beach.booking_window_days', 30, 'number');
        Setting::set('beach.season_start', today()->subDays(10)->toDateString(), 'text');
        Setting::set('beach.season_end', today()->addDays(5)->toDateString(), 'text');

        // Brenda sezonit → OK.
        $this->post(route('website.beach.submit'), $this->payload(
            today()->addDays(4)->toDateString(),
            today()->addDays(5)->toDateString(),
        ))->assertSessionHasNoErrors();

        // Jashtë sezonit (pas mbylljes) → 422 edhe pse brenda dritares.
        $this->postJson(route('website.beach.submit'), $this->payload(
            today()->addDays(6)->toDateString(),
            today()->addDays(7)->toDateString(),
        ))->assertStatus(422);
    }

    public function test_confirmation_only_via_token(): void
    {
        $start = today()->addDay()->toDateString();

        $response = $this->post(route('website.beach.submit'), $this->payload($start, $start));
        $reservation = BeachReservation::sole();

        $response->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));

        $this->get(route('website.beach.confirmation', $reservation->confirmation_token))
            ->assertOk();
        $this->get(route('website.beach.confirmation', 'token-i-gabuar-qe-s-ekziston-fare-00000000'))
            ->assertNotFound();

        $this->assertSame(BeachReservation::STATUS_PENDING, $reservation->status);
        $this->assertSame(BeachReservation::SOURCE_WEBSITE, $reservation->source);
        $this->assertSame('800.00', $reservation->total_amount);
        $this->assertNotNull($reservation->created_by);
    }

    public function test_public_routes_carry_throttle(): void
    {
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('website.beach.availability')->middleware());
        $this->assertContains('throttle:10,1', Route::getRoutes()->getByName('website.beach.submit')->middleware());
        $this->assertContains('module:beach', Route::getRoutes()->getByName('website.beach')->middleware());
    }

    public function test_disabled_module_blocks_public_page(): void
    {
        $tenant = Tenant::query()->sole();
        $metadata = $tenant->metadata;
        $metadata['billing_access']['modules']['beach'] = false;
        $tenant->update(['metadata' => $metadata]);

        $this->get(route('website.beach'))->assertForbidden();
        $this->postJson(route('website.beach.submit'), $this->payload(
            today()->addDay()->toDateString(),
            today()->addDay()->toDateString(),
        ))->assertForbidden();
    }

    public function test_payment_page_guards(): void
    {
        // Fik POK plotësisht → zero thirrje reale API nga testi: edhe fallback-un
        // e testing-ut, edhe integrimin që migrimi tenant-core e mbush nga .env.
        config(['services.pok.testing_legacy_fallback' => false]);
        \App\Models\TenantIntegration::withoutGlobalScopes()->where('provider', 'pok')->update(['enabled' => false]);

        $start = today()->addDay()->toDateString();
        $this->post(route('website.beach.submit'), $this->payload($start, $start));
        $reservation = BeachReservation::sole();

        // Pa POK të konfiguruar (testing) → kthehet te konfirmimi, kurrë formë karte.
        $this->get(route('website.beach.pay', $reservation->confirmation_token))
            ->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));

        // Token i gabuar → 404.
        $this->get(route('website.beach.pay', 'token-i-gabuar-000000000000000000000000000'))
            ->assertNotFound();

        // I paguar tashmë → guard ripagese: gjithmonë kthim te konfirmimi.
        $reservation->update(['paid_at' => now(), 'pok_order_id' => 'ord-test']);
        $this->get(route('website.beach.pay', $reservation->confirmation_token))
            ->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));
        $this->post(route('website.beach.pay.confirm', $reservation->confirmation_token))
            ->assertRedirect(route('website.beach.confirmation', $reservation->confirmation_token));
    }

    public function test_inactive_unit_cannot_be_booked(): void
    {
        $this->unit->update(['is_active' => false]);
        $start = today()->addDay()->toDateString();

        $this->postJson(route('website.beach.submit'), $this->payload($start, $start))
            ->assertStatus(422);
        $this->assertSame(0, BeachReservation::count());
    }
}
