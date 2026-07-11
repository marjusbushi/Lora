<?php

namespace Tests\Feature;

use App\Jobs\Middleware\UseTenantContext;
use App\Jobs\PushRoomTypeAri;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\ChannexConfiguration;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_installation_is_backfilled_into_a_default_tenant(): void
    {
        $tenant = Tenant::query()->sole();

        $this->assertSame(config('app.name'), $tenant->name);
        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $tenant->id,
            'domain' => 'localhost',
            'is_primary' => true,
        ]);
    }

    public function test_operational_models_and_unique_business_keys_are_isolated_by_tenant(): void
    {
        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create(['name' => 'Hotel Two']);

        $context->set($first);
        $firstType = RoomType::create(['name' => 'Standard', 'base_price' => 80, 'max_occupancy' => 2]);
        Room::create(['room_type_id' => $firstType->id, 'room_number' => '101']);
        Setting::set('hotel.name', 'Hotel One');

        $context->set($second);
        $secondType = RoomType::create(['name' => 'Standard', 'base_price' => 90, 'max_occupancy' => 2]);
        Room::create(['room_type_id' => $secondType->id, 'room_number' => '101']);
        Setting::set('hotel.name', 'Hotel Two');

        $this->assertSame(['101'], Room::pluck('room_number')->all());
        $this->assertSame('Hotel Two', Setting::get('hotel.name'));

        $context->set($first);

        $this->assertSame(['101'], Room::pluck('room_number')->all());
        $this->assertSame($firstType->id, Room::firstOrFail()->room_type_id);
        $this->assertSame('Hotel One', Setting::get('hotel.name'));
    }

    public function test_route_model_binding_cannot_load_a_record_from_another_tenant(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        $context->set($first);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $context->set($second);
        $foreignGuest = Guest::create([
            'first_name' => 'Foreign',
            'last_name' => 'Guest',
            'email' => 'foreign@example.test',
        ]);

        $context->clear();

        $this->actingAs($admin)
            ->get(route('guests.show', $foreignGuest))
            ->assertNotFound();
    }

    public function test_only_super_admin_can_create_and_switch_tenants(): void
    {
        $default = Tenant::query()->sole();
        app(TenantContext::class)->set($default);

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $regularUser = User::factory()->create();

        app(TenantContext::class)->clear();

        $this->actingAs($regularUser)
            ->get(route('super-admin.tenants.index'))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tenants.index'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.store'), [
                'name' => 'Hotel Riviera',
                'slug' => 'hotel-riviera',
                'primary_domain' => 'riviera.lorapms.test',
                'timezone' => 'Europe/Tirane',
                'currency' => 'EUR',
            ])
            ->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'hotel-riviera')->firstOrFail();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $superAdmin->id,
            'is_owner' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.switch', $tenant))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('tenant_id', $tenant->id);

        $this->assertSame($tenant->id, $superAdmin->fresh()->current_tenant_id);
    }

    public function test_validation_rejects_room_and_guest_ids_from_another_tenant(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        $context->set($first);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $context->set($second);
        $foreignType = RoomType::create(['name' => 'Foreign Type', 'base_price' => 90, 'max_occupancy' => 2]);
        $foreignRoom = Room::create(['room_type_id' => $foreignType->id, 'room_number' => '909']);
        $foreignGuest = Guest::create([
            'first_name' => 'Foreign',
            'last_name' => 'Guest',
            'email' => 'foreign-validation@example.test',
        ]);

        $context->clear();

        $this->actingAs($admin)
            ->post(route('reservations.store'), [
                'room_id' => $foreignRoom->id,
                'guest_id' => $foreignGuest->id,
                'check_in_date' => '2026-08-01',
                'check_out_date' => '2026-08-03',
                'adults' => 2,
            ])
            ->assertSessionHasErrors(['room_id', 'guest_id']);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_external_integrations_and_queued_jobs_keep_their_tenant_context(): void
    {
        config([
            'services.channex.testing_legacy_fallback' => false,
            'services.channex.api_key' => 'must-not-leak',
            'services.channex.property_id' => 'must-not-leak',
        ]);

        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        $context->set($first);
        TenantIntegration::create([
            'provider' => 'channex',
            'enabled' => true,
            'credentials' => ['api_key' => 'tenant-one-key', 'webhook_secret' => 'tenant-one-secret'],
            'configuration' => [
                'property_id' => 'tenant-one-property',
                'base_url' => 'https://app.channex.io/api/v1',
                'state_length_days' => 500,
            ],
        ]);
        $job = new PushRoomTypeAri(999999);

        $context->set($second);
        $this->assertFalse(app(ChannexConfiguration::class)->configured());

        (new UseTenantContext($job->tenantId))->handle($job, function () use ($context, $first) {
            $this->assertSame($first->id, $context->id());
            $this->assertSame('tenant-one-property', app(ChannexConfiguration::class)->get('property_id'));
        });

        $this->assertSame($second->id, $context->id());
    }
}
