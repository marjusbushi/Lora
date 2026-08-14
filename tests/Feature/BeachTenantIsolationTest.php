<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BeachTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantB;

    private BeachZone $zoneB;

    private BeachUnit $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantB = Tenant::factory()->create([
            'status' => 'active',
            'metadata' => [
                'billing_access' => ['status' => 'active', 'modules' => ['beach' => true]],
            ],
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $this->tenantB->id,
            'domain' => 'beachb.test',
            'is_primary' => true,
        ]);

        app(TenantContext::class)->run($this->tenantB, function () {
            $this->zoneB = BeachZone::create(['name' => 'Zona B', 'price_per_day' => 500]);
            $this->unitB = $this->zoneB->units()->create(['number' => '1']);
        });
    }

    public function test_pms_user_of_tenant_a_cannot_touch_tenant_b_resources(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(); // tenant A (default)
        $admin->assignRole('admin');

        // Binding-u kalon nga scope-i global i tenant A → 404, kurrë të dhëna të B.
        $this->actingAs($admin)
            ->putJson(route('beach.zones.update', $this->zoneB->id), ['name' => 'Hacked', 'price_per_day' => 1])
            ->assertNotFound();
        $this->actingAs($admin)
            ->deleteJson(route('beach.zones.destroy', $this->zoneB->id))
            ->assertNotFound();
        $this->actingAs($admin)
            ->putJson(route('beach.units.update', $this->unitB->id), ['number' => '99', 'is_active' => true])
            ->assertNotFound();

        $this->assertSame('Zona B', $this->zoneB->fresh()->name);

        // Edhe si input id (jo binding): rezervimi me çadrën e tenant B → 422 nga TenantRule.
        $this->actingAs($admin)->postJson(route('beach.reservations.store'), [
            'beach_unit_id' => $this->unitB->id,
            'start_date' => today()->addDay()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'guest_name' => 'Sulmuesi', 'guest_phone' => '069',
        ])->assertStatus(422);

        $this->assertSame(0, BeachReservation::withoutGlobalScopes()->count());
    }

    public function test_public_host_sees_only_its_own_tenant_beach(): void
    {
        // Tenant A (default) ka zonën e vet.
        BeachZone::create(['name' => 'Zona A', 'price_per_day' => 800]);

        $this->get('https://beachb.test/book-sunbeds')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // shouldExist=false: faqja Vue ndërtohet në task #273 — testi mbron props-at.
                ->component('Website/BookSunbeds', false)
                ->has('zones', 1)
                ->where('zones.0.name', 'Zona B'));
    }

    public function test_public_submit_on_host_b_rejects_tenant_a_unit(): void
    {
        $zoneA = BeachZone::create(['name' => 'Zona A', 'price_per_day' => 800]);
        $unitA = $zoneA->units()->create(['number' => '7']);

        $start = today()->addDay()->toDateString();

        $this->postJson('https://beachb.test/book-sunbeds', [
            'beach_unit_id' => $unitA->id,
            'start_date' => $start, 'end_date' => $start,
            'guest_name' => 'Guest Test', 'guest_phone' => '069',
        ])->assertStatus(422);

        $this->assertSame(0, BeachReservation::withoutGlobalScopes()->count());
    }
}
