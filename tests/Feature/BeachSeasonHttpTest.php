<?php

namespace Tests\Feature;

use App\Models\BeachSeason;
use App\Models\BeachZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeachSeasonHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_season_via_http_happy_path(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $zone = BeachZone::create(['name' => 'Rreshti 1', 'price_per_day' => 9]);

        $this->actingAs($admin)->post(route('beach.seasons.store'), [
            'name' => 'Tetor Test',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-15',
            'prices' => [$zone->id => 12],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, BeachSeason::count());

        // Edhe update + rates-save — rrugët e tjera të mutacionit të sezoneve.
        $season = BeachSeason::sole();
        $this->actingAs($admin)->put(route('beach.seasons.update', $season), [
            'name' => 'Tetor Test 2',
            'start_date' => '2026-10-02',
            'end_date' => '2026-10-16',
            'prices' => [$zone->id => 14],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($admin)->post(route('beach.seasons.rates.save'), [
            'rates' => [$season->id => [$zone->id => 15]],
        ])->assertSessionHasNoErrors()->assertRedirect();
    }
}
