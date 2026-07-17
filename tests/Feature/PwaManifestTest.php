<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_public_standalone_and_tenant_branded(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create(['name' => 'Hotel B']);

        TenantDomain::query()->create([
            'tenant_id' => $first->id,
            'domain' => 'hotel-a.test',
            'is_primary' => false,
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $second->id,
            'domain' => 'hotel-b.test',
            'is_primary' => true,
        ]);

        Cache::put('app.settings', ['hotel_name' => 'Wrong Global Brand']);

        $context = app(TenantContext::class);
        $context->run($first, fn () => Setting::set('hotel.name', 'Hotel A'));
        $context->run($second, fn () => Setting::set('hotel.name', 'Hotel B'));

        $staff = User::factory()->create(['current_tenant_id' => $first->id]);
        $first->users()->syncWithoutDetaching([
            $staff->id => ['is_owner' => false, 'is_active' => true],
        ]);

        $this->actingAs($staff)
            ->withSession(['tenant_id' => $first->id])
            ->get('https://hotel-a.test/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertJson([
                'name' => 'Hotel A',
                'display' => 'standalone',
                'start_url' => '/dashboard',
                'scope' => '/',
            ])
            ->assertJsonCount(2, 'icons');

        $this->get('https://hotel-b.test/manifest.webmanifest')
            ->assertOk()
            ->assertJson([
                'name' => 'Hotel B',
                'short_name' => 'Hotel B',
            ]);
    }

    public function test_manifest_uses_the_current_tenants_setting_on_the_default_hotel_host(): void
    {
        $tenant = Tenant::query()->sole();

        app(TenantContext::class)->run(
            $tenant,
            fn () => Setting::set('hotel.name', 'Database Hotel'),
        );

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJson([
                'name' => 'Database Hotel',
                'short_name' => 'Database Hotel',
            ]);
    }

    public function test_manifest_icons_exist_at_the_advertised_sizes(): void
    {
        foreach (['icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
            $path = public_path($file);
            $this->assertFileExists($path);
            [$w, $h] = getimagesize($path);
            $this->assertSame([$size, $size], [$w, $h], $file);
        }
    }

    public function test_every_tenant_page_ships_the_installed_app_metas(): void
    {
        $this->withoutVite();

        app(TenantContext::class)->run(
            Tenant::query()->sole(),
            fn () => Setting::set('hotel.name', 'Hotel A'),
        );

        // The login page renders through the same app.blade.php shell as the
        // whole PMS — one shell, so standalone mode holds on every page.
        $this->get('/login')
            ->assertOk()
            ->assertSee('<title inertia>Hotel A</title>', false)
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('apple-mobile-web-app-title" content="Hotel A', false)
            ->assertSee('mobile-web-app-capable', false);
    }

    public function test_control_panel_does_not_publish_a_tenants_pwa_identity(): void
    {
        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);

        $this->withoutVite();

        $this->get('https://admin.lorapms.test/login')
            ->assertOk()
            ->assertSee('<title inertia>Lora PMS</title>', false)
            ->assertDontSee('rel="manifest"', false)
            ->assertDontSee('apple-mobile-web-app-title', false);

        $this->get('https://admin.lorapms.test/manifest.webmanifest')
            ->assertNotFound();
    }
}
