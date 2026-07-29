<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\DomainProvisioner;
use App\Services\ForgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The custom-domain lifecycle: real DNS verification against the platform
 * server IP, Forge provisioning (site + shared root + certificate) with
 * graceful degradation when Forge is not configured, and super-admin-only
 * access to every action.
 */
class TenantDomainProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Tenant $tenant;

    private TenantDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lora.control_panel_hosts' => ['localhost'],
            'services.forge.server_ip' => '178.104.114.222',
            'services.forge.app_root' => '/home/lorapms/lorapms.com/current/public',
        ]);
        $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->tenant = Tenant::query()->sole();
        $this->domain = $this->tenant->domains()->create(['domain' => 'hotelicastle.al', 'is_primary' => false]);
    }

    private function fakeDns(array $records): void
    {
        $provisioner = Mockery::mock(DomainProvisioner::class, [app(ForgeClient::class)])->makePartial();
        $provisioner->shouldReceive('aRecords')->andReturn($records);
        $this->app->instance(DomainProvisioner::class, $provisioner);
    }

    public function test_dns_verification_passes_when_the_a_record_points_at_the_server(): void
    {
        $this->fakeDns(['178.104.114.222']);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.verify', [$this->tenant, $this->domain]))
            ->assertRedirect()->assertSessionHas('success');

        $fresh = $this->domain->fresh();
        $this->assertNotNull($fresh->verified_at);
        $this->assertNull($fresh->status_message);
    }

    public function test_dns_verification_reports_the_wrong_records_it_found(): void
    {
        $this->fakeDns(['1.2.3.4']);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.verify', [$this->tenant, $this->domain]))
            ->assertRedirect()->assertSessionHas('error');

        $fresh = $this->domain->fresh();
        $this->assertNull($fresh->verified_at);
        $this->assertSame(TenantDomain::STATUS_PENDING_DNS, $fresh->status);
        $this->assertStringContainsString('1.2.3.4', $fresh->status_message);
        $this->assertStringContainsString('178.104.114.222', $fresh->status_message);
    }

    public function test_provisioning_without_a_forge_token_degrades_with_a_clear_message(): void
    {
        $this->fakeDns(['178.104.114.222']);
        $this->domain->forceFill(['verified_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.provision', [$this->tenant, $this->domain]))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertStringContainsString('Forge API i pakonfiguruar', $this->domain->fresh()->status_message);
    }

    public function test_full_provisioning_creates_the_site_points_the_root_and_activates_on_cert(): void
    {
        config(['services.forge.token' => 'ft_test', 'services.forge.server_id' => '99']);
        $this->fakeDns(['178.104.114.222']);
        $this->domain->forceFill(['verified_at' => now()])->save();

        // Laravel's HTTP fake matches URL PREFIXES — specific patterns first.
        Http::fake([
            'forge.laravel.com/api/v1/servers/99/sites/555/nginx' => Http::sequence()
                ->push('server { root /home/lorapms/hotelicastle.al/public; }')
                ->push(['ok' => true]),
            'forge.laravel.com/api/v1/servers/99/sites/555/certificates/letsencrypt' => Http::response(['certificate' => ['id' => 7]]),
            'forge.laravel.com/api/v1/servers/99/sites/555/certificates' => Http::response([
                'certificates' => [['id' => 7, 'status' => 'installed', 'active' => true]],
            ]),
            'forge.laravel.com/api/v1/servers/99/sites' => Http::sequence()
                // provision(): lookup finds nothing, then the site is created.
                ->push(['sites' => []])
                ->push(['site' => ['id' => 555, 'name' => 'hotelicastle.al']])
                // refreshStatus(): lookup now finds the site.
                ->push(['sites' => [['id' => 555, 'name' => 'hotelicastle.al']]]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.provision', [$this->tenant, $this->domain]))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(TenantDomain::STATUS_ACTIVE, $this->domain->fresh()->status);

        // The nginx root was rewritten onto the shared application.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sites/555/nginx')
                && $request->method() === 'PUT'
                && str_contains((string) ($request->data()['content'] ?? ''), 'root /home/lorapms/lorapms.com/current/public;');
        });
    }

    public function test_refresh_reports_pending_certificate_until_it_installs(): void
    {
        config(['services.forge.token' => 'ft_test', 'services.forge.server_id' => '99']);
        $this->domain->forceFill(['status' => TenantDomain::STATUS_PROVISIONING])->save();

        Http::fake([
            'forge.laravel.com/api/v1/servers/99/sites' => Http::response(['sites' => [['id' => 555, 'name' => 'hotelicastle.al']]]),
            'forge.laravel.com/api/v1/servers/99/sites/555/certificates' => Http::response(['certificates' => []]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.tenants.domains.refresh', [$this->tenant, $this->domain]))
            ->assertRedirect();

        $fresh = $this->domain->fresh();
        $this->assertSame(TenantDomain::STATUS_PROVISIONING, $fresh->status);
        $this->assertStringContainsString('Certifikata', $fresh->status_message);
    }

    public function test_domain_actions_are_super_admin_only(): void
    {
        $this->fakeDns(['178.104.114.222']);
        $regular = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($regular)
            ->post(route('super-admin.tenants.domains.verify', [$this->tenant, $this->domain]))
            ->assertForbidden();
    }
}
