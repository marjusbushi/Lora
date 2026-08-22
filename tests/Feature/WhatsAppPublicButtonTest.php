<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Butoni WhatsApp i webit publik (task #338): numri ruhet te Cilësimet →
 * Të dhënat e hotelit; bosh = butoni s'shfaqet (front-i lexon settings të
 * përbashkëta — shared props).
 */
class WhatsAppPublicButtonTest extends TestCase
{
    use RefreshDatabase;

    // Numri WhatsApp u zhvendos te Web Studio → Kontakt (task #415):
    // updateHotel s'i pranon më fushat e kontaktit.
    private function contactPayload(array $override = []): array
    {
        return array_merge([
            'address' => null, 'phone' => null, 'email' => null,
            'instagram' => null, 'facebook' => null, 'maps_url' => null,
        ], $override);
    }

    public function test_admin_saves_the_whatsapp_number_on_hotel_settings(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('web-studio.contact'), $this->contactPayload([
            'whatsapp_number' => '+355 69 123 4567',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('+355 69 123 4567', Setting::get('hotel.whatsapp_number'));

        // Pastrimi: bosh = butoni fiket në web (Setting e ruan null-in si '' —
        // front-i e trajton '' si të pavendosur, njësoj si telefonin).
        $this->actingAs($admin)->put(route('web-studio.contact'), $this->contactPayload([
            'whatsapp_number' => null,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('', (string) Setting::get('hotel.whatsapp_number'));
    }

    public function test_public_pages_receive_the_number_via_shared_settings(): void
    {
        // Zinxhiri i plotë (gjetje Codex #438: whitelist-i i shared props e
        // mbante numrin të padukshëm): ruajtja → prop-i settings i faqes publike.
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('web-studio.contact'), $this->contactPayload([
            'whatsapp_number' => '+355 69 123 4567',
        ]))->assertRedirect();

        $this->get(route('website.home'))->assertInertia(
            fn ($page) => $page->where('settings.whatsapp_number', '+355 69 123 4567')
        );
    }

    public function test_double_zero_prefix_is_normalized_to_plus_on_save(): void
    {
        // wa.me/00355… hap faqe bosh (gjetur live, task #340) — ruajtja e
        // kthen 00-shen në '+' që çdo konsumator të marrë vlerë të vlefshme.
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('web-studio.contact'), $this->contactPayload([
            'whatsapp_number' => '00355 69 203 0020',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('+355 69 203 0020', Setting::get('hotel.whatsapp_number'));
    }

    public function test_garbage_whatsapp_number_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('web-studio.contact'), $this->contactPayload([
            'whatsapp_number' => 'jo numer <script>',
        ]))->assertSessionHasErrors('whatsapp_number');
    }
}
