<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Channex CLI commands must resolve their client AFTER ensureTenantContext:
 * constructor injection built it before handle() ran, so per-tenant credentials
 * (tenant_integrations) were invisible and the command claimed the API key was
 * missing — exactly what blocked Saturn's go-live with an empty global .env.
 */
class ChannexCommandTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_sees_the_tenant_stored_credentials(): void
    {
        // Exercise the PRODUCTION resolution path: no legacy env fallback,
        // empty global key, credentials only on the tenant integration row.
        config([
            'services.channex.testing_legacy_fallback' => false,
            'services.channex.api_key' => '',
        ]);

        $tenant = Tenant::query()->sole();
        app(\App\Services\TenantBillingService::class)->provision($tenant, enableAll: true);
        TenantIntegration::create([
            'tenant_id' => $tenant->id,
            'provider' => 'channex',
            'enabled' => true,
            'credentials' => ['api_key' => 'tenant-key-123', 'webhook_secret' => 's3cret'],
            'configuration' => ['property_id' => 'ab257930-2edd-4835-af11-737f6c2001bb'],
        ]);

        Http::fake([
            'app.channex.io/api/v1/properties*' => Http::response(['data' => [
                ['id' => 'ab257930-2edd-4835-af11-737f6c2001bb', 'attributes' => ['title' => 'Saturn Apart Hotel']],
            ]], 200),
        ]);

        $this->artisan('channex:ping', ['--tenant' => $tenant->id])
            ->expectsOutputToContain('Channex connection OK')
            ->assertSuccessful();

        // The request went out carrying the TENANT's key, not the global one.
        Http::assertSent(fn ($request) => $request->hasHeader('user-api-key', 'tenant-key-123')
            || str_contains((string) ($request->header('Authorization')[0] ?? ''), 'tenant-key-123'));
    }
}
