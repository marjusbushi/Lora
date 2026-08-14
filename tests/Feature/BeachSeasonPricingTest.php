<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachSeason;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BeachPricing;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sezonet e çmimeve të plazhit: resolver-i ditë-për-ditë (fallback bazë,
 * kufij inkluzivë, interval që kap dy sezone), CRUD me anti-mbivendosje,
 * totali VETËM server-side dhe izolimi multi-tenant.
 */
class BeachSeasonPricingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: BeachZone, 1: BeachUnit} */
    private function zoneWithUnit(float $base = 9, string $name = 'Rreshti 1', string $number = '1'): array
    {
        $zone = BeachZone::create(['name' => $name, 'price_per_day' => $base]);
        $unit = $zone->units()->create(['number' => $number]);

        return [$zone, $unit];
    }

    /** @param array<int, float> $prices beach_zone_id => çmim/ditë */
    private function season(string $name, string $start, string $end, array $prices = []): BeachSeason
    {
        $season = BeachSeason::create(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
        foreach ($prices as $zoneId => $price) {
            $season->prices()->create(['beach_zone_id' => $zoneId, 'price_per_day' => $price]);
        }

        return $season;
    }

    private function actingAsAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    // ── Resolver-i ────────────────────────────────────────────────────────

    public function test_no_season_uses_base_price_per_day(): void
    {
        [, $unit] = $this->zoneWithUnit(9);

        // 3 ditë inkluzive × 9
        $this->assertEqualsWithDelta(27.0, app(BeachPricing::class)->totalFor($unit, '2026-08-15', '2026-08-17'), 0.001);
    }

    public function test_interval_inside_season_uses_seasonal_price(): void
    {
        [$zone, $unit] = $this->zoneWithUnit(9);
        $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone->id => 12]);

        $this->assertEqualsWithDelta(36.0, app(BeachPricing::class)->totalFor($unit, '2026-08-15', '2026-08-17'), 0.001);
    }

    public function test_interval_spanning_two_seasons_and_a_gap_sums_each_day(): void
    {
        [$zone, $unit] = $this->zoneWithUnit(9);
        $this->season('Fillimi', '2026-08-10', '2026-08-15', [$zone->id => 12]);
        $this->season('Piku', '2026-08-17', '2026-08-20', [$zone->id => 20]);

        // 14→12, 15→12, 16→9 (ditë pa sezon), 17→20, 18→20 = 73
        $this->assertEqualsWithDelta(73.0, app(BeachPricing::class)->totalFor($unit, '2026-08-14', '2026-08-18'), 0.001);
    }

    public function test_season_boundary_dates_are_inclusive_on_both_ends(): void
    {
        [$zone, $unit] = $this->zoneWithUnit(9);
        $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone->id => 12]);

        $pricing = app(BeachPricing::class);
        $this->assertEqualsWithDelta(12.0, $pricing->totalFor($unit, '2026-08-10', '2026-08-10'), 0.001);
        $this->assertEqualsWithDelta(12.0, $pricing->totalFor($unit, '2026-08-20', '2026-08-20'), 0.001);
        $this->assertEqualsWithDelta(9.0, $pricing->totalFor($unit, '2026-08-09', '2026-08-09'), 0.001);
        $this->assertEqualsWithDelta(9.0, $pricing->totalFor($unit, '2026-08-21', '2026-08-21'), 0.001);
    }

    public function test_zone_without_a_price_row_in_active_season_falls_back_to_base(): void
    {
        [$zone1] = $this->zoneWithUnit(9, 'Rreshti 1', '1');
        [, $unit2] = $this->zoneWithUnit(5, 'Rreshti 2', '2');
        $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone1->id => 12]); // vetëm zona 1

        $this->assertEqualsWithDelta(15.0, app(BeachPricing::class)->totalFor($unit2, '2026-08-15', '2026-08-17'), 0.001);
    }

    public function test_breakdown_reports_interval_total_and_daily_min_max(): void
    {
        [$zone] = $this->zoneWithUnit(9);
        $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone->id => 12]);

        // 19,20 → 12 · 21 → 9
        $breakdown = app(BeachPricing::class)->breakdown(BeachZone::query()->get(), '2026-08-19', '2026-08-21');

        $this->assertEqualsWithDelta(33.0, $breakdown[$zone->id]['total'], 0.001);
        $this->assertEqualsWithDelta(9.0, $breakdown[$zone->id]['min_daily'], 0.001);
        $this->assertEqualsWithDelta(12.0, $breakdown[$zone->id]['max_daily'], 0.001);
    }

    // ── CRUD i sezoneve ──────────────────────────────────────────────────

    public function test_overlapping_season_rejected_and_self_update_allowed(): void
    {
        $this->actingAsAdmin();
        [$zone] = $this->zoneWithUnit(9);

        $this->post(route('beach.seasons.store'), [
            'name' => 'Gusht', 'start_date' => '2026-08-10', 'end_date' => '2026-08-20',
            'prices' => [$zone->id => 12],
        ])->assertSessionHasNoErrors();

        $season = BeachSeason::query()->firstOrFail();
        $this->assertEqualsWithDelta(12.0, (float) $season->prices()->firstOrFail()->price_per_day, 0.001);

        // Mbivendosje → 422 me mesazh që emërton sezonin konfliktual.
        $response = $this->postJson(route('beach.seasons.store'), [
            'name' => 'Korrik', 'start_date' => '2026-08-15', 'end_date' => '2026-08-25',
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('Gusht', $response->json('errors.start_date.0'));
        $this->assertSame(1, BeachSeason::query()->count());

        // Update i vetes me të njëjtat data lejohet (excludon veten nga kontrolli).
        $this->put(route('beach.seasons.update', $season->id), [
            'name' => 'Gusht i ri', 'start_date' => '2026-08-10', 'end_date' => '2026-08-20',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Gusht i ri', $season->fresh()->name);
    }

    public function test_deleting_a_season_restores_base_price_and_cascades_prices(): void
    {
        $this->actingAsAdmin();
        [$zone, $unit] = $this->zoneWithUnit(9);
        $season = $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone->id => 12]);

        $this->delete(route('beach.seasons.destroy', $season->id))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(27.0, app(BeachPricing::class)->totalFor($unit, '2026-08-15', '2026-08-17'), 0.001);
        $this->assertDatabaseCount('beach_season_prices', 0); // cascade, pa jetimë
    }

    public function test_rates_save_updates_base_and_clears_seasonal_on_empty(): void
    {
        $this->actingAsAdmin();
        [$zone] = $this->zoneWithUnit(9);
        $season = $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone->id => 12]);

        $this->post(route('beach.seasons.rates.save'), [
            'base' => [$zone->id => 11],
            'rates' => [$season->id => [$zone->id => '']], // bosh = kthehu te baza
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(11.0, (float) $zone->fresh()->price_per_day, 0.001);
        $this->assertDatabaseCount('beach_season_prices', 0);
    }

    // ── Siguria ──────────────────────────────────────────────────────────

    public function test_public_submit_ignores_any_client_price_and_uses_seasonal_total(): void
    {
        [$zone, $unit] = $this->zoneWithUnit(9);
        $start = today()->addDay();
        $this->season('Tani', today()->toDateString(), today()->addDays(5)->toDateString(), [$zone->id => 12]);

        // Klienti "dërgon" çmimet e veta — serveri s'i lexon fare.
        $this->post(route('website.beach.submit'), [
            'beach_unit_id' => $unit->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'guest_name' => 'Guest Test', 'guest_phone' => '069123',
            'total_amount' => 0.01, 'price' => 0.01, 'price_per_day' => 0.01,
        ])->assertSessionHasNoErrors();

        $reservation = BeachReservation::query()->firstOrFail();
        $this->assertEqualsWithDelta(24.0, (float) $reservation->total_amount, 0.001); // 2 ditë × 12 sezonale
    }

    public function test_tenant_b_seasons_unreachable_from_tenant_a_admin(): void
    {
        $this->actingAsAdmin(); // tenant A (default)

        $tenantB = Tenant::factory()->create([
            'status' => 'active',
            'metadata' => ['billing_access' => ['status' => 'active', 'modules' => ['beach' => true]]],
        ]);

        [$seasonB, $zoneB] = app(TenantContext::class)->run($tenantB, function () {
            $zoneB = BeachZone::create(['name' => 'Zona B', 'price_per_day' => 500]);

            return [
                BeachSeason::create(['name' => 'Sezoni B', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']),
                $zoneB,
            ];
        });

        // Binding-u kalon nga scope-i i tenant A → 404, kurrë të dhënat e B.
        $this->putJson(route('beach.seasons.update', $seasonB->id), [
            'name' => 'Hacked', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ])->assertNotFound();
        $this->deleteJson(route('beach.seasons.destroy', $seasonB->id))->assertNotFound();

        // Edhe si id në payload: rates.save i tenant A s'prek dot zonën e B.
        $this->post(route('beach.seasons.rates.save'), ['base' => [$zoneB->id => 1]]);

        app(TenantContext::class)->run($tenantB, function () use ($seasonB, $zoneB) {
            $this->assertSame('Sezoni B', $seasonB->fresh()->name);
            $this->assertEqualsWithDelta(500.0, (float) $zoneB->fresh()->price_per_day, 0.001);
        });
    }

    // ── Regresioni ───────────────────────────────────────────────────────

    public function test_existing_reservation_total_is_frozen_when_seasons_change(): void
    {
        [$zone, $unit] = $this->zoneWithUnit(9);

        $reservation = BeachReservation::create([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Guest Test', 'guest_phone' => '069123',
            'start_date' => '2026-08-15', 'end_date' => '2026-08-17',
            'status' => BeachReservation::STATUS_CONFIRMED,
            'source' => BeachReservation::SOURCE_RECEPTION,
            'total_amount' => 27,
        ]);

        $this->season('Gusht', '2026-08-10', '2026-08-20', [$zone->id => 100]);

        $this->assertEqualsWithDelta(27.0, (float) $reservation->fresh()->total_amount, 0.001);
    }

    public function test_availability_endpoint_returns_zone_pricing_with_busy_ids(): void
    {
        [$zone] = $this->zoneWithUnit(9);
        $start = today()->addDay();
        $this->season('Tani', today()->toDateString(), today()->addDays(5)->toDateString(), [$zone->id => 12]);

        $this->getJson(route('website.beach.availability', [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('zone_pricing.'.$zone->id.'.total', 24)
            ->assertJsonPath('zone_pricing.'.$zone->id.'.min_daily', 12)
            ->assertJsonStructure(['busy_unit_ids', 'zone_pricing']);
    }
}
