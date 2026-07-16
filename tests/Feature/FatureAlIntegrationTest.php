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
use Illuminate\Http\UploadedFile;
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

            $integration = TenantIntegration::query()->where('provider', 'fature_al')->firstOrFail();
            $values = $integration->configuration;
            $values['last_test_status'] = 'success';
            $values['last_tested_at'] = now()->toIso8601String();
            $integration->forceFill(['configuration' => $values])->save();
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
        $this->assertArrayNotHasKey('last_test_status', $integration->configuration);

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

    public function test_super_admin_can_complete_fature_al_onboarding_wizard_with_identifiable_user_agent(): void
    {
        config([
            'services.fature_al.onboarding_token' => 'partner-onboarding-token',
            'services.fature_al.app_name' => 'LoraPMS',
            'services.fature_al.build_version' => 'test-build',
        ]);
        $tenant = Tenant::factory()->create(['name' => 'Hotel Wizard', 'currency' => 'EUR']);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/register' => Http::response([
                'status' => true,
                'data' => [
                    'user' => ['token' => 'tenant-fiscal-token', 'id' => 701],
                    'branch' => ['id' => 801, 'name' => 'Hotel Wizard'],
                ],
            ]),
            'https://demo.fature.al/api/v1/on-boarding/certificate' => Http::response([
                'status' => true, 'data' => ['cert' => ['expiresAt' => '2027-07-16']],
            ]),
            'https://demo.fature.al/api/v1/on-boarding/branch/801' => Http::response([
                'status' => true, 'data' => ['branch' => ['id' => 801, 'name' => 'Hotel Wizard', 'businessUnitCode' => 'BU001']],
            ]),
            'https://demo.fature.al/api/v1/on-boarding/fiscal-device' => Http::response([
                'status' => true, 'data' => ['device' => ['fiscalTcrCode' => 'TCR-001']],
            ]),
            'https://demo.fature.al/api/v1/on-boarding/user/701' => Http::response([
                'status' => true, 'data' => ['user' => ['id' => 701, 'name' => 'Operator', 'operatorCode' => 'OP001']],
            ]),
            'https://demo.fature.al/api/v1/on-boarding/bank-account' => Http::response([
                'status' => true, 'data' => ['bankAccount' => ['id' => 901, 'iban' => 'AL47212110090000000235698741']],
            ]),
            'https://demo.fature.al/api/v1/account' => Http::response([
                'status' => true,
                'data' => [
                    'company' => 'Hotel Wizard', 'nipt' => 'L62221018T',
                    'branch' => ['name' => 'Hotel Wizard'], 'vatConfigs' => ['issuerInVat' => true],
                ],
            ]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.onboarding.fiscalization.register', $tenant), [
                'environment' => 'sandbox', 'nuis' => 'L62221018T', 'name' => 'Hotel Wizard',
                'address' => 'Tirane', 'administrator' => 'Admin Hotel', 'phone' => '0690000000',
                'email' => 'fiscal@example.test', 'issuer_in_vat' => true,
                'last_non_cash_einvoice_number' => null, 'uses_cash' => true,
            ])->assertSessionHasNoErrors();

        $this->post(route('super-admin.onboarding.fiscalization.certificate', $tenant), [
            'certificate' => UploadedFile::fake()->create('hotel.p12', 10, 'application/x-pkcs12'),
            'password' => 'certificate-secret',
        ])->assertSessionHasNoErrors();
        $this->post(route('super-admin.onboarding.fiscalization.branch', $tenant), [
            'name' => 'Hotel Wizard', 'business_unit_code' => 'BU001',
            'administrator' => 'Admin Hotel', 'address' => 'Tirane',
        ])->assertSessionHasNoErrors();
        $this->post(route('super-admin.onboarding.fiscalization.device', $tenant), [
            'name' => 'Main TCR', 'from_date' => '2026-07-16', 'to_date' => null,
        ])->assertSessionHasNoErrors();
        $this->post(route('super-admin.onboarding.fiscalization.user', $tenant), [
            'name' => 'Operator', 'operator_code' => 'OP001',
        ])->assertSessionHasNoErrors();
        $this->post(route('super-admin.onboarding.fiscalization.bank-account', $tenant), [
            'name' => 'Banka', 'holder' => 'Hotel Wizard', 'iban' => 'AL47212110090000000235698741',
            'swift' => 'AAAAALTR', 'currency' => 'EUR', 'notes' => null,
        ])->assertSessionHasNoErrors();
        $this->post(route('super-admin.onboarding.fiscalization.verify', $tenant))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $integration = TenantIntegration::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('provider', 'fature_al')->firstOrFail();
        $this->assertTrue($integration->enabled);
        $this->assertSame('tenant-fiscal-token', $integration->credentials['api_token']);
        $this->assertSame('TCR-001', $integration->configuration['onboarding']['fiscal_tcr_code']);
        $this->assertSame('success', $integration->configuration['last_test_status']);
        $this->assertTrue((bool) data_get($tenant->onboarding()->firstOrFail()->steps, 'integrations.tasks.fature_al.completed'));

        $page = $this->get(route('super-admin.onboarding.fiscalization.show', $tenant));
        $page->assertOk()->assertInertia(fn (Assert $view) => $view
            ->component('SuperAdmin/Onboarding/FatureAl')
            ->where('fiscalization.status', 'ready')
            ->where('fiscalization.progress', 100)
            ->where('fiscalization.has_api_token', true));
        $this->assertStringNotContainsString('tenant-fiscal-token', $page->getContent());

        $raw = (string) DB::table('tenant_integrations')->where('id', $integration->id)->value('credentials');
        $this->assertStringNotContainsString('tenant-fiscal-token', $raw);
        $this->assertStringNotContainsString('certificate-secret', json_encode($integration->configuration));

        Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', 'LoraPMS/test-build'));
        $this->assertTrue(Http::recorded()->every(
            fn (array $exchange) => $exchange[0]->hasHeader('User-Agent', 'LoraPMS/test-build'),
        ));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/register'
            && $request->hasHeader('Authorization', 'Bearer partner-onboarding-token'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/account'
            && $request->hasHeader('Authorization', 'Bearer tenant-fiscal-token'));
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
                    'vatConfigs' => ['issuerInVat' => 'true'],
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
        $this->assertSame([
            'company' => 'Sandbox Hotel',
            'nipt' => 'L00000000A',
            'branch' => 'Main',
            'issuer_in_vat' => true,
        ], $integration->configuration['account']);
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
