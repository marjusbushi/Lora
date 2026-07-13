<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_public_standalone_and_branded(): void
    {
        Cache::put('app.settings', ['hotel_name' => 'Villa Mucho']);

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'Villa Mucho',
                'display' => 'standalone',
                'start_url' => '/dashboard',
                'scope' => '/',
            ])
            ->assertJsonCount(2, 'icons');
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

    public function test_every_page_ships_the_installed_app_metas(): void
    {
        $this->withoutVite();

        // The login page renders through the same app.blade.php shell as the
        // whole PMS — one shell, so standalone mode holds on every page.
        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('mobile-web-app-capable', false);
    }
}
