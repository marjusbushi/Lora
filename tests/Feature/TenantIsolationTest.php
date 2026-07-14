<?php

namespace Tests\Feature;

use App\Jobs\Middleware\UseTenantContext;
use App\Jobs\PushRoomTypeAri;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\ChannexConfiguration;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
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

    public function test_hotel_application_does_not_expose_lora_control_panel_routes(): void
    {
        $default = Tenant::query()->sole();
        TenantDomain::query()->create([
            'tenant_id' => $default->id,
            'domain' => 'admin.villamucho.test',
            'is_primary' => false,
        ]);

        app(TenantContext::class)->set($default);

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $regularUser = User::factory()->create();

        app(TenantContext::class)->clear();

        $this->actingAs($regularUser)
            ->get('https://admin.villamucho.test/super-admin/tenants')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('https://admin.villamucho.test/super-admin/tenants')
            ->assertRedirect(config('lora.control_panel_url').'/super-admin/tenants');

        $this->actingAs($superAdmin)
            ->post('https://admin.villamucho.test/super-admin/tenants', [
                'name' => 'Hotel Riviera',
                'slug' => 'hotel-riviera',
                'primary_domain' => 'riviera.lorapms.test',
                'timezone' => 'Europe/Tirane',
                'currency' => 'EUR',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('tenants', ['slug' => 'hotel-riviera']);
    }

    public function test_only_super_admin_can_create_and_switch_tenants(): void
    {
        config(['lora.control_panel_hosts' => ['localhost']]);

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

        app(TenantContext::class)->set($tenant);
        $this->assertSame(6, Role::query()->where('team_id', $tenant->id)->count());
        $this->assertTrue(Role::query()->where('team_id', $tenant->id)->where('name', 'maintenance')->exists());
        $this->assertTrue($superAdmin->unsetRelation('roles')->hasRole('admin'));
        app(TenantContext::class)->clear();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.switch', $tenant))
            ->assertRedirect('http://riviera.lorapms.test/dashboard')
            ->assertSessionHas('tenant_id', $tenant->id);

        $this->assertSame($tenant->id, $superAdmin->fresh()->current_tenant_id);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.switch',
            'causer_id' => $superAdmin->id,
        ]);
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
    public function test_console_writes_without_context_fail_closed_outside_testing(): void
    {
        app(TenantContext::class)->clear();
        $this->app['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            RoomType::create(['name' => 'Phantom', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => []]);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_integration_credentials_are_not_used_without_context_outside_testing(): void
    {
        config([
            'services.channex.api_key' => 'legacy-key',
            'services.channex.property_id' => 'PROP-LEGACY',
            'services.pok.key_id' => 'legacy-pok',
            'services.pok.key_secret' => 'legacy-secret',
            'services.pok.merchant_id' => 'legacy-merchant',
        ]);
        app(TenantContext::class)->clear();
        $this->app['env'] = 'production';

        try {
            $this->assertFalse(app(ChannexConfiguration::class)->configured());
            $this->assertSame('', app(ChannexConfiguration::class)->get('api_key'));
            $this->assertFalse(app(\App\Services\PokConfiguration::class)->configured());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_manual_tenant_commands_require_the_tenant_option_outside_testing(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan('housekeeping:archive-inspected')->assertFailed();

            $this->artisan('housekeeping:archive-inspected', [
                '--tenant' => Tenant::query()->sole()->id,
            ])->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
    public function test_pok_order_ids_are_unique_per_tenant_not_globally(): void
    {
        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        foreach ([$first, $second] as $i => $tenant) {
            $context->set($tenant);
            $type = RoomType::create(['name' => 'Std', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => []]);
            $room = Room::create(['room_type_id' => $type->id, 'room_number' => '10'.$i, 'floor' => 1, 'status' => 'available']);
            $guest = Guest::create(['first_name' => 'G', 'last_name' => (string) $i, 'email' => "g{$i}@example.test"]);
            $staff = User::factory()->create();

            Reservation::create([
                'room_id' => $room->id,
                'guest_id' => $guest->id,
                'created_by' => $staff->id,
                'check_in_date' => today()->addDays(2)->toDateString(),
                'check_out_date' => today()->addDays(4)->toDateString(),
                'status' => 'pending',
                'total_amount' => 100,
                'adults' => 2,
                'pok_order_id' => 'POK-SAME-ORDER',
            ]);
        }

        $context->clear();

        // Both hotels hold the SAME POK order id — no cross-tenant collision.
        $this->assertSame(
            2,
            Reservation::withoutGlobalScopes()->where('pok_order_id', 'POK-SAME-ORDER')->count(),
        );
    }
}
