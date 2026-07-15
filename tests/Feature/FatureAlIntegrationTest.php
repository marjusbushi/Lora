<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\FatureAlConfiguration;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FatureAlIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lora.control_panel_hosts' => ['localhost']]);
        $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
    }

    public function test_token_is_encrypted_tenant_scoped_and_never_echoed(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Hotel Fiscal']);
        $other = Tenant::factory()->create(['name' => 'Hotel Other']);
        $token = 'synthetic-test-token-that-must-stay-secret';

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tenants.integrations.update', [$tenant->id, 'fature_al']), [
                'enabled' => true,
                'api_token' => $token,
                'environment' => 'sandbox',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $integration = TenantIntegration::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'fature_al')
            ->firstOrFail();

        $this->assertSame($token, $integration->credentials['api_token']);
        $this->assertSame('sandbox', $integration->configuration['environment']);
        $this->assertStringNotContainsString(
            $token,
            (string) DB::table('tenant_integrations')->where('id', $integration->id)->value('credentials'),
        );
        $this->assertDatabaseMissing('tenant_integrations', [
            'tenant_id' => $other->id,
            'provider' => 'fature_al',
        ]);

        app(TenantContext::class)->run($tenant, function () use ($token) {
            $configuration = app(FatureAlConfiguration::class);
            $this->assertSame($token, $configuration->get('api_token'));
            $this->assertSame('https://demo.fature.al/api/v1', $configuration->get('base_url'));
        });

        // Blank token means keep the encrypted value while updating non-secret config.
        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tenants.integrations.update', [$tenant->id, 'fature_al']), [
                'enabled' => true,
                'api_token' => '',
                'environment' => 'production',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($token, $integration->refresh()->credentials['api_token']);
        $this->assertSame('production', $integration->configuration['environment']);

        $response = $this->actingAs($this->superAdmin)->get(route('super-admin.tenants.index'));
        $response->assertOk();
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_enabled_integration_requires_a_token(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tenants.integrations.update', [$tenant->id, 'fature_al']), [
                'enabled' => true,
                'api_token' => '',
                'environment' => 'sandbox',
            ])
            ->assertSessionHasErrors('api_token');
    }

    public function test_connection_check_is_read_only_and_records_success(): void
    {
        $tenant = Tenant::factory()->create();
        $token = 'synthetic-sandbox-token';

        app(TenantContext::class)->run($tenant, fn () => TenantIntegration::query()->create([
            'provider' => 'fature_al',
            'enabled' => true,
            'credentials' => ['api_token' => $token],
            'configuration' => ['environment' => 'sandbox'],
        ]));

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/account' => Http::response([
                'status' => true,
                'data' => [
                    'company' => 'Sandbox Hotel',
                    'nipt' => 'L00000000A',
                    'branch' => ['name' => 'Main'],
                ],
            ]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.integrations.test', [$tenant->id, 'fature_al']))
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://demo.fature.al/api/v1/account'
            && $request->hasHeader('Authorization', 'Bearer '.$token));

        $integration = TenantIntegration::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'fature_al')
            ->firstOrFail();
        $this->assertSame('success', $integration->configuration['last_test_status']);
        $this->assertNotEmpty($integration->configuration['last_tested_at']);
    }

    public function test_hotel_integration_center_exposes_status_but_not_token(): void
    {
        $tenant = Tenant::query()->sole();
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);

        app(TenantRoleService::class)->provision($tenant);
        app(TenantContext::class)->run($tenant, function () use ($admin) {
            $admin->assignRole('admin');
            TenantIntegration::query()->create([
                'provider' => 'fature_al',
                'enabled' => true,
                'credentials' => ['api_token' => 'never-send-this-token'],
                'configuration' => ['environment' => 'sandbox'],
            ]);
        });

        $response = $this->actingAs($admin)
            ->get(route('settings.index', ['tab' => 'integrations']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->has('integrations', 6)
                ->where('integrations.2.id', 'fature_al')
                ->where('integrations.2.configured', true)
                ->where('integrations.2.environment', 'sandbox'));

        $this->assertStringNotContainsString('never-send-this-token', $response->getContent());
    }
}
