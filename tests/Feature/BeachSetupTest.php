<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeachSetupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_setup_requires_view_beach_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('housekeeping'); // s'ka asnjë leje beach

        $this->actingAs($user)->get(route('beach.setup'))->assertForbidden();
    }

    public function test_setup_requires_beach_module(): void
    {
        $admin = $this->admin();

        $tenant = Tenant::query()->sole();
        $metadata = $tenant->metadata;
        $metadata['billing_access']['modules']['beach'] = false;
        $tenant->update(['metadata' => $metadata]);

        $this->actingAs($admin)->get(route('beach.setup'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('beach.setup'))->assertRedirect(route('login'));
    }

    public function test_bulk_generate_creates_sequential_units_with_unique_qr(): void
    {
        $admin = $this->admin();
        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => 800]);

        $this->actingAs($admin)
            ->post(route('beach.units.generate', $zone), ['count' => 20])
            ->assertSessionHasNoErrors();

        $this->assertSame(20, BeachUnit::count());
        $this->assertSame(
            array_map('strval', range(1, 20)),
            BeachUnit::orderBy('sort_order')->pluck('number')->all(),
        );
        $this->assertSame(20, BeachUnit::distinct('qr_token')->count('qr_token'));
        $this->assertSame(40, strlen(BeachUnit::first()->qr_token));

        // Zona e dytë vazhdon numërimin nga max-i i tenant-it — kurrë përplasje.
        $zone2 = BeachZone::create(['name' => 'Rreshti 2', 'price_per_day' => 500]);
        $this->actingAs($admin)
            ->post(route('beach.units.generate', $zone2), ['count' => 5])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            array_map('strval', range(21, 25)),
            $zone2->units()->orderBy('sort_order')->pluck('number')->all(),
        );
    }

    public function test_delete_zone_with_reservations_returns_422_and_keeps_data(): void
    {
        $admin = $this->admin();
        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => 800]);
        $unit = $zone->units()->create(['number' => '1']);
        BeachReservation::create([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Test Guest', 'guest_phone' => '069',
            'start_date' => today()->addDay()->toDateString(),
            'end_date' => today()->addDays(2)->toDateString(),
            'status' => BeachReservation::STATUS_CONFIRMED,
            'source' => BeachReservation::SOURCE_RECEPTION,
        ]);

        $this->actingAs($admin)->deleteJson(route('beach.zones.destroy', $zone))->assertStatus(422);
        $this->assertDatabaseHas('beach_zones', ['id' => $zone->id]);

        // Edhe me rezervim të anulluar mbetet historik — sugjerohet çaktivizimi.
        BeachReservation::query()->update(['status' => BeachReservation::STATUS_CANCELLED]);
        $this->actingAs($admin)->deleteJson(route('beach.zones.destroy', $zone))->assertStatus(422);

        // Pa asnjë rezervim → fshihet bashkë me çadrat.
        BeachReservation::query()->delete();
        $this->actingAs($admin)->delete(route('beach.zones.destroy', $zone))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('beach_zones', ['id' => $zone->id]);
        $this->assertDatabaseMissing('beach_units', ['id' => $unit->id]);
    }

    public function test_update_beach_settings_validates_and_persists(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(route('settings.beach'), ['booking_window_days' => 400])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson(route('settings.beach'), [
                'booking_window_days' => 15,
                'season_start' => '2026-05-01',
                'season_end' => '2026-04-01',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->put(route('settings.beach'), [
                'booking_window_days' => 15,
                'season_start' => '2026-05-01',
                'season_end' => '2026-09-30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, (int) Setting::get('beach.booking_window_days'));
        $this->assertSame('2026-05-01', (string) Setting::get('beach.season_start'));
    }
}
