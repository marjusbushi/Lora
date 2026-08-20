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

    public function test_production_onboarding_registers_on_live_with_the_production_partner_token(): void
    {
        // Renato 2026-08-19: real registrations run on live. Invoice ISSUING
        // stays sandbox-locked separately (proven below) — registering a
        // company on production cannot create any invoice.
        config([
            'services.fature_al.onboarding_token' => 'sandbox-partner-token',
            'services.fature_al.onboarding_token_production' => 'live-partner-token',
        ]);
        $tenant = Tenant::factory()->create();

        Http::preventStrayRequests();
        Http::fake([
            'https://fature.al/api/v1/register' => Http::response([
                'status' => true,
                'data' => [
                    'user' => ['id' => 77, 'token' => 'live-company-token'],
                    'branch' => ['id' => 88, 'name' => 'Villa Mucho - Selia'],
                ],
            ]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.onboarding.fiscalization.register', $tenant), [
                'environment' => 'production', 'nuis' => 'K33713881K', 'name' => 'Villa Mucho',
                'address' => 'Lagjja nr.4, Rruga Mitat Hoxha', 'administrator' => 'Luan Muco',
                'phone' => '0690000000', 'email' => 'live@example.test', 'issuer_in_vat' => true,
                'last_non_cash_einvoice_number' => null, 'uses_cash' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // The LIVE host and the PRODUCTION partner token — never the sandbox one.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://fature.al/api/v1/register'
            && $request->hasHeader('Authorization', 'Bearer live-partner-token'));

        $integration = TenantIntegration::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('provider', 'fature_al')->firstOrFail();
        $this->assertSame('production', $integration->configuration['environment']);
        $this->assertFalse((bool) $integration->enabled); // enabled only after final verify
    }

    public function test_production_onboarding_without_the_production_token_fails_loudly_and_sends_nothing(): void
    {
        config([
            'services.fature_al.onboarding_token' => 'sandbox-partner-token',
            'services.fature_al.onboarding_token_production' => null,
        ]);
        $tenant = Tenant::factory()->create();

        Http::preventStrayRequests();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.onboarding.fiscalization.register', $tenant), [
                'environment' => 'production', 'nuis' => 'L12345678A', 'name' => 'Hotel Live',
                'address' => 'Tirane', 'administrator' => 'Admin', 'phone' => '0690000000',
                'email' => 'live@example.test', 'issuer_in_vat' => true,
                'last_non_cash_einvoice_number' => null, 'uses_cash' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['fature_al']);

        // The sandbox token must never be cross-used on live.
        Http::assertNothingSent();
    }

    public function test_invoice_issuing_remains_sandbox_locked_even_for_a_production_configured_tenant(): void
    {
        // The other half of the unlock's safety story: a tenant configured for
        // production can be REGISTERED, but fiscalizing anything refuses.
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->run($tenant, fn () => TenantIntegration::query()->create([
            'provider' => 'fature_al',
            'enabled' => true,
            'credentials' => ['api_token' => 'live-company-token'],
            'configuration' => ['environment' => 'production', 'last_test_status' => 'success'],
        ]));

        app(TenantContext::class)->run($tenant, function () {
            $reservation = new \App\Models\Reservation();
            try {
                app(\App\Services\ReservationFiscalizationService::class)->payload($reservation);
                $this->fail('production fiscalization should have been refused');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertStringContainsString('sandbox', implode(' ', $exception->errors()['fiscalization'] ?? []));
            }
        });
    }

    public function test_client_identity_headers_are_sent_only_when_both_credentials_are_set(): void
    {
        // The solution-provider layer (fature.al, 2026-08): both headers or
        // neither. Empty or half-configured pairs must never reach the wire —
        // an unknown id is rejected 401 on the spot, while absent headers
        // remain valid until fature.al's enforcement date.
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->run($tenant, fn () => TenantIntegration::query()->create([
            'provider' => 'fature_al',
            'enabled' => true,
            'credentials' => ['api_token' => 'synthetic-sandbox-token'],
            'configuration' => ['environment' => 'sandbox'],
        ]));

        $accountResponse = Http::response([
            'status' => true,
            'data' => [
                'company' => 'Sandbox Hotel', 'nipt' => 'L00000000A',
                'branch' => ['name' => 'Main'], 'vatConfigs' => ['issuerInVat' => 'true'],
            ],
        ]);

        // Fully configured pair → both headers on the request.
        config(['services.fature_al.client_id' => 'ft_id_test123', 'services.fature_al.client_secret' => 'ft_sk_test456']);
        Http::preventStrayRequests();
        Http::fake(['https://demo.fature.al/api/v1/account' => $accountResponse]);
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.integrations.test', [$tenant->id, 'fature_al']))
            ->assertRedirect();
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Client-Id', 'ft_id_test123')
            && $request->hasHeader('X-Client-Secret', 'ft_sk_test456'));

        // Half-configured (secret missing) → NEITHER header is sent.
        config(['services.fature_al.client_id' => 'ft_id_test123', 'services.fature_al.client_secret' => null]);
        Http::fake(['https://demo.fature.al/api/v1/account' => $accountResponse]);
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.integrations.test', [$tenant->id, 'fature_al']))
            ->assertRedirect();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/account'
            && ! $request->hasHeader('X-Client-Id')
            && ! $request->hasHeader('X-Client-Secret'));
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
        // Key order is engine-dependent: MySQL's JSON type reorders object
        // keys (length, then bytes) while SQLite preserves insertion order —
        // sort before comparing so the assertion holds on both.
        $account = $integration->configuration['account'];
        ksort($account);
        $this->assertSame([
            'branch' => 'Main',
            'company' => 'Sandbox Hotel',
            'issuer_in_vat' => true,
            'nipt' => 'L00000000A',
        ], $account);
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
