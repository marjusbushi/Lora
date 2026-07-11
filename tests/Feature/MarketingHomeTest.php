<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketingHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_lora_product_hosts_render_the_tenantless_marketing_page(): void
    {
        foreach (['lorapms.com', 'staging.lorapms.com'] as $host) {
            $this->get("https://{$host}/")
                ->assertOk()
                ->assertSee('Lora PMS — Menaxho hotelin. Jo kaosin.', false)
                ->assertSee('/lora-favicon.svg?v=1', false)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Marketing/Home')
                    ->where('settings.hotel_name', 'Lora PMS')
                    ->where('tenant', null));
        }
    }

    public function test_www_lora_host_redirects_to_the_canonical_product_domain(): void
    {
        $this->get('https://www.lorapms.com/?source=website')
            ->assertStatus(308)
            ->assertRedirect('https://lorapms.com/?source=website');
    }

    public function test_unknown_unmapped_host_stays_closed(): void
    {
        $this->get('https://unknown-hotel.example/')
            ->assertNotFound();
    }

    public function test_product_homepage_never_exposes_a_mapped_tenants_context(): void
    {
        $tenant = Tenant::query()->sole();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'staging.lorapms.com',
            'is_primary' => false,
        ]);

        $this->get('https://staging.lorapms.com/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Home')
                ->where('tenant', null)
                ->where('settings.hotel_name', 'Lora PMS'));
    }

    public function test_lora_product_host_login_is_available_without_a_tenant_domain(): void
    {
        $this->get('https://lorapms.com/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('settings.hotel_name', 'Lora PMS')
                ->where('tenant', null));
    }
}
