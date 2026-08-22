<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Web Studio (task #411) — paneli i vetëm i webit publik. Kontratat kryesore:
 * çelësat e ruajtjes janë PIKËRISHT ata që lexon faqja publike (grupet hotel + about)
 * dhe ruajtja e "Të dhënave të hotelit" NUK i prek më tekstet e hero-s.
 */
class WebStudioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_index_aggregates_home_contact_brand_about_and_room_cards(): void
    {
        Setting::set('hotel.hero_title_sq', 'Një vend ku ndihesh si në shtëpi.');
        Setting::set('hotel.instagram', 'https://instagram.com/villa.mucho');
        Setting::set('about.story_p1_sq', 'Historia jonë nis në Sarandë.');

        $type = \App\Models\RoomType::create(['name' => 'Deluxe', 'base_price' => 120, 'max_occupancy' => 2, 'amenities' => []]);
        \App\Models\RoomTypeImage::create(['room_type_id' => $type->id, 'path' => 'room-types/deluxe.jpg', 'sort_order' => 1]);

        $this->actingAs($this->admin())->get(route('web-studio.index'))
            ->assertInertia(fn ($page) => $page
                ->component('WebStudio/Index')
                ->where('home.hero_title_sq', 'Një vend ku ndihesh si në shtëpi.')
                ->where('contact.instagram', 'https://instagram.com/villa.mucho')
                ->where('about.story_p1_sq', 'Historia jonë nis në Sarandë.')
                ->where('roomTypes.0.name', 'Deluxe')
                ->where('roomTypes.0.image', 'room-types/deluxe.jpg')
                ->where('completeness.about', true));
    }

    public function test_update_home_writes_the_exact_keys_the_public_site_reads(): void
    {
        $this->actingAs($this->admin())->post(route('web-studio.home'), [
            'hero_eyebrow_sq' => 'Sarande - Albania',
            'hero_eyebrow_en' => 'Saranda',
            'hero_title_sq' => 'Një vend ku ndihesh si në shtëpi.',
            'hero_title_en' => 'A Place to Feel at Home.',
            'hero_subtitle_sq' => 'Diell, Det dhe Konfort.',
            'hero_subtitle_en' => 'Sun, Sea and Comfort',
        ])->assertRedirect();

        $this->assertSame('Sarande - Albania', Setting::get('hotel.hero_eyebrow_sq'));
        $this->assertSame('A Place to Feel at Home.', Setting::get('hotel.hero_title_en'));
        $this->assertSame('Diell, Det dhe Konfort.', Setting::get('hotel.hero_subtitle_sq'));
    }

    public function test_saving_hotel_data_no_longer_wipes_hero_texts(): void
    {
        Setting::set('hotel.hero_title_sq', 'Titull i shkruar në Web Studio');
        Setting::set('hotel.hero_eyebrow_sq', 'Sarande - Albania');

        // Forma e "Të dhënave të hotelit" — pa asnjë fushë hero (ashtu dërgon UI-ja e re).
        $this->actingAs($this->admin())->put(route('settings.hotel'), [
            'name' => 'Villa Mucho',
            'timezone' => 'Europe/Tirane',
            'currency' => $this->tenant->currency ?: 'EUR',
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
        ])->assertRedirect();

        $this->assertSame('Titull i shkruar në Web Studio', Setting::get('hotel.hero_title_sq'));
        $this->assertSame('Sarande - Albania', Setting::get('hotel.hero_eyebrow_sq'));
    }

    public function test_update_contact_normalizes_whatsapp_and_saves_socials(): void
    {
        $this->actingAs($this->admin())->put(route('web-studio.contact'), [
            'address' => 'Rruga e Plazhit, Sarandë',
            'phone' => '+355 69 111 2222',
            'email' => 'info@villamucho.com',
            'whatsapp_number' => '00355691112222',
            'instagram' => 'https://instagram.com/villa.mucho',
            'facebook' => '',
            'maps_url' => '',
        ])->assertRedirect();

        $this->assertSame('+355691112222', Setting::get('hotel.whatsapp_number'));
        $this->assertSame('Rruga e Plazhit, Sarandë', Setting::get('hotel.address'));
        $this->assertSame('https://instagram.com/villa.mucho', Setting::get('hotel.instagram'));
    }

    public function test_web_studio_routes_require_admin_role(): void
    {
        $viewer = User::factory()->create(['current_tenant_id' => $this->tenant->id]);

        $this->actingAs($viewer)->get(route('web-studio.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('web-studio.home'), [])->assertForbidden();
        $this->actingAs($viewer)->put(route('web-studio.contact'), [])->assertForbidden();
    }
}
