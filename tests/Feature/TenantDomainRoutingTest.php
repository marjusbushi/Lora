<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_www_requests_are_permanently_redirected_to_the_canonical_domain(): void
    {
        $this->get('https://www.villamucho.com/rooms?adults=2')
            ->assertStatus(308)
            ->assertRedirect('https://villamucho.com/rooms?adults=2');
    }

    public function test_admin_domain_can_resolve_the_hotel_before_authentication(): void
    {
        $tenant = Tenant::query()->sole();

        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'admin.villamucho.com',
            'is_primary' => false,
        ]);

        $this->get('https://admin.villamucho.com/login')->assertOk();
    }
}
