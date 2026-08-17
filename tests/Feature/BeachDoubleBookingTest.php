<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeachDoubleBookingTest extends TestCase
{
    use RefreshDatabase;

    private BeachUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => 800]);
        $this->unit = $zone->units()->create(['number' => '1']);
    }

    /** @return array<string, mixed> */
    private function payload(string $name = 'Guest One'): array
    {
        $start = today()->addDays(2)->toDateString();

        return [
            'beach_unit_id' => $this->unit->id,
            'start_date' => $start,
            'end_date' => today()->addDays(4)->toDateString(),
            'guest_name' => $name, 'guest_phone' => '069123',
        ];
    }

    public function test_second_public_submit_for_same_unit_and_dates_is_refused(): void
    {
        $this->post(route('website.beach.submit'), $this->payload())
            ->assertSessionHasNoErrors();

        // I dyti bie në re-check-un brenda transaksionit me lock — kurrë të dy.
        $this->postJson(route('website.beach.submit'), $this->payload('Guest Two'))
            ->assertStatus(422);

        $this->assertSame(1, BeachReservation::count());
    }

    public function test_reception_and_public_cannot_double_book_the_same_unit(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Publiku e zë çadrën i pari…
        $this->post(route('website.beach.submit'), $this->payload())
            ->assertSessionHasNoErrors();

        // …recepsioni provon të njëjtat data → 422, edhe pse pa kufi dritareje.
        $this->actingAs($admin)->postJson(route('beach.reservations.store'), $this->payload('Nga Telefoni'))
            ->assertStatus(422);

        $this->assertSame(1, BeachReservation::count());
        $this->assertSame(BeachReservation::SOURCE_WEBSITE, BeachReservation::sole()->source);
    }
}
