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

    private function hotelPayload(array $override = []): array
    {
        return array_merge([
            'name' => 'Villa Mucho',
            'timezone' => 'Europe/Tirane',
            'currency' => 'EUR',
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
        ], $override);
    }

    public function test_admin_saves_the_whatsapp_number_on_hotel_settings(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('settings.hotel'), $this->hotelPayload([
            'whatsapp_number' => '+355 69 123 4567',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('+355 69 123 4567', Setting::get('hotel.whatsapp_number'));

        // Pastrimi: bosh = butoni fiket në web (Setting e ruan null-in si '' —
        // front-i e trajton '' si të pavendosur, njësoj si telefonin).
        $this->actingAs($admin)->put(route('settings.hotel'), $this->hotelPayload([
            'whatsapp_number' => null,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('', (string) Setting::get('hotel.whatsapp_number'));
    }

    public function test_garbage_whatsapp_number_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('settings.hotel'), $this->hotelPayload([
            'whatsapp_number' => 'jo numer <script>',
        ]))->assertSessionHasErrors('whatsapp_number');
    }
}
