<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\ChannexConfiguration;
use App\Services\TenantOnboardingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The control-panel onboarding path: owner, integrations, domains — no DB hand-editing. */
class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lora.control_panel_hosts' => ['localhost']]);

        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $this->superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);
        app(TenantContext::class)->clear();
    }

    public function test_creating_a_tenant_with_an_owner_provisions_the_owner_account(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.store'), [
                'name' => 'Hotel Riviera',
                'slug' => 'hotel-riviera',
                'primary_domain' => 'riviera.lorapms.test',
                'timezone' => 'Europe/Tirane',
                'currency' => 'EUR',
                'owner_name' => 'Ana Berisha',
                'owner_email' => 'ana@riviera.test',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $tenant = Tenant::query()->where('slug', 'hotel-riviera')->firstOrFail();
        $owner = User::withoutGlobalScopes()->where('email', 'ana@riviera.test')->firstOrFail();

        $this->assertSame($tenant->id, $owner->current_tenant_id);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'is_owner' => true,
            'is_active' => true,
        ]);

        app(TenantContext::class)->set($tenant);
        $this->assertTrue($owner->unsetRelation('roles')->hasRole('admin'));
        app(TenantContext::class)->clear();
    }

    public function test_tenant_form_exposes_supported_currencies_and_iana_timezones(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SuperAdmin/Tenants/Index')
                ->where('currencyOptions', config('lora.tenant_currencies'))
                ->has('currencyOptions', 10)
                ->where('timezoneGroups.Europe', fn ($timezones) => collect($timezones)
                    ->contains('value', 'Europe/Tirane')));
    }

    public function test_every_onboarding_task_has_a_destination_and_exchange_api_is_included(): void
    {
        $tenant = Tenant::query()->sole();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.onboarding.show', $tenant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SuperAdmin/Onboarding/Show')
                ->where('onboarding.steps.6.tasks.3.key', 'exchange_rates')
                ->where('onboarding.steps.6.tasks.3.action.path', '/settings?tab=currencies'));

        $tasks = collect(config('onboarding.steps'))->flatMap(fn (array $step) => $step['tasks']);
        $this->assertTrue($tasks->every(fn (array $task) => isset($task['action']['type'])));
    }

    public function test_existing_onboarding_records_receive_new_tasks_from_the_catalog(): void
    {
        $tenant = Tenant::query()->sole();
        $service = app(TenantOnboardingService::class);
        $onboarding = $service->findOrCreate($tenant);
        $steps = $onboarding->steps;
        unset($steps['integrations']['tasks']['exchange_rates']);
        $onboarding->forceFill(['steps' => $steps])->save();

        $synced = $service->findOrCreate($tenant);

        $this->assertArrayHasKey('exchange_rates', $synced->steps['integrations']['tasks']);
        $this->assertFalse($synced->steps['integrations']['tasks']['exchange_rates']['completed']);
    }

    public function test_tenant_creation_rejects_a_currency_outside_the_supported_list(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.store'), [
                'name' => 'Unsupported Currency Hotel',
                'slug' => 'unsupported-currency-hotel',
                'timezone' => 'Europe/Tirane',
                'currency' => 'BTC',
            ])
            ->assertSessionHasErrors('currency');

        $this->assertDatabaseMissing('tenants', ['slug' => 'unsupported-currency-hotel']);
    }

    public function test_integration_credentials_are_saved_per_tenant_and_never_echoed(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Hotel B']);
        app(\App\Services\TenantBillingService::class)->provision($tenant, enableAll: true);

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tenants.integrations.update', [$tenant->id, 'channex']), [
                'enabled' => true,
                'api_key' => 'secret-channex-key',
                'webhook_secret' => 'secret-webhook',
                'property_id' => 'PROP-B',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $integration = TenantIntegration::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('provider', 'channex')->firstOrFail();
        $this->assertSame('secret-channex-key', $integration->credentials['api_key']);
        $this->assertSame('PROP-B', $integration->configuration['property_id']);

        // The tenant's own Channex config resolves from ITS integration row.
        config(['services.channex.testing_legacy_fallback' => false]);
        app(TenantContext::class)->run($tenant, function () {
            $this->assertSame('secret-channex-key', app(ChannexConfiguration::class)->get('api_key'));
        });

        // Blank secret submit = keep the stored key; non-secret fields update.
        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tenants.integrations.update', [$tenant->id, 'channex']), [
                'enabled' => true,
                'api_key' => '',
                'webhook_secret' => '',
                'property_id' => 'PROP-B2',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $integration->refresh();
        $this->assertSame('secret-channex-key', $integration->credentials['api_key']);
        $this->assertSame('PROP-B2', $integration->configuration['property_id']);

        // The index page exposes only PRESENCE of secrets, never their values.
        $response = $this->actingAs($this->superAdmin)->get(route('super-admin.tenants.index'));
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('SuperAdmin/Tenants/Index'));
        $this->assertStringNotContainsString('secret-channex-key', $response->getContent());
        $this->assertStringNotContainsString('secret-webhook', $response->getContent());

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.integration.update',
        ]);
    }

    public function test_domains_can_be_added_made_primary_and_removed(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Hotel C']);
        $primary = TenantDomain::query()->create([
            'tenant_id' => $tenant->id, 'domain' => 'hotelc.test', 'is_primary' => true,
        ]);

        // Add a second domain.
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.store', $tenant->id), ['domain' => 'admin.hotelc.test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $second = TenantDomain::query()->where('domain', 'admin.hotelc.test')->firstOrFail();
        $this->assertFalse($second->is_primary);

        // A domain already used by ANOTHER hotel is rejected (global uniqueness).
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.store', $tenant->id), ['domain' => 'localhost'])
            ->assertSessionHasErrors('domain');

        // The primary domain cannot be deleted…
        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.tenants.domains.destroy', [$tenant->id, $primary->id]))
            ->assertSessionHasErrors('domain');

        // …but a promoted one takes over, then the old one can go.
        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.tenants.domains.primary', [$tenant->id, $second->id]))
            ->assertRedirect();
        $this->assertTrue($second->refresh()->is_primary);
        $this->assertFalse($primary->refresh()->is_primary);

        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.tenants.domains.destroy', [$tenant->id, $primary->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('tenant_domains', ['id' => $primary->id]);

        // A domain of hotel C cannot be managed through ANOTHER tenant's URL.
        $other = Tenant::factory()->create();
        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.tenants.domains.destroy', [$other->id, $second->id]))
            ->assertNotFound();

        $this->assertSame(
            3,
            AuditLog::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('action', ['tenant.domain.create', 'tenant.domain.primary', 'tenant.domain.delete'])
                ->count(),
        );
    }
    public function test_enabling_channex_without_a_property_id_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tenants.integrations.update', [$tenant->id, 'channex']), [
                'enabled' => true,
                'api_key' => 'some-key',
                'property_id' => '',
            ])
            ->assertSessionHasErrors('property_id');
    }
    public function test_suspending_a_hotel_locks_it_out_and_activating_restores_it(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Hotel D', 'status' => 'active']);
        app(\App\Services\TenantBillingService::class)->provision($tenant, enableAll: true);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id, 'domain' => 'hoteld.test', 'is_primary' => true,
        ]);

        // Super-admin actions live on the control-panel host (localhost here) —
        // use explicit URLs so a preceding request to the HOTEL domain can't
        // repoint route() at the wrong host.
        $cp = fn (string $path) => 'http://localhost'.$path;

        // Active hotel resolves on its own domain.
        $this->get('https://hoteld.test/rooms')->assertOk();

        // Suspend it.
        $this->actingAs($this->superAdmin)
            ->patch($cp("/super-admin/tenants/{$tenant->id}/status"), ['status' => 'suspended'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('suspended', $tenant->fresh()->status);

        // Now its domain 404s and it cannot be switched into.
        $this->get('https://hoteld.test/rooms')->assertNotFound();
        $this->actingAs($this->superAdmin)
            ->post($cp("/super-admin/tenants/{$tenant->id}/switch"))
            ->assertStatus(422);

        // Reactivate → live again.
        $this->actingAs($this->superAdmin)
            ->patch($cp("/super-admin/tenants/{$tenant->id}/status"), ['status' => 'active'])
            ->assertRedirect();
        $this->assertSame('active', $tenant->fresh()->status);
        $this->get('https://hoteld.test/rooms')->assertOk();

        $this->assertSame(
            2,
            AuditLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('action', 'tenant.status')->count(),
        );
    }

    public function test_status_only_accepts_active_or_suspended(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.tenants.status', $tenant->id), ['status' => 'deleted'])
            ->assertSessionHasErrors('status');
    }
}
