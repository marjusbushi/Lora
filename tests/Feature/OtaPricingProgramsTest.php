<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\OtaPricingPrograms;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtaPricingProgramsTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_promotions_preserve_the_target_guest_price(): void
    {
        Setting::set('pricing_programs.booking_genius_enabled', '1', 'boolean');
        Setting::set('pricing_programs.booking_genius_pct', '15', 'number');
        Setting::set('pricing_programs.booking_mobile_enabled', '1', 'boolean');
        Setting::set('pricing_programs.booking_mobile_pct', '10', 'number');
        Setting::set('financial.channel_fees', ['booking.com' => 18], 'json');

        $booking = OtaPricingPrograms::quote(85)['booking'];

        $this->assertEquals(111.11, $booking['published_price']);
        $this->assertEquals(85.0, round($booking['published_price'] * .85 * .90, 2));
        $this->assertEquals(69.70, $booking['estimated_net']);
        $this->assertEquals(30.72, $booking['required_modifier_pct']);
    }

    public function test_expedia_programs_are_calculated_independently(): void
    {
        Setting::set('pricing_programs.expedia_member_enabled', '1', 'boolean');
        Setting::set('pricing_programs.expedia_member_pct', '10', 'number');
        Setting::set('pricing_programs.expedia_mobile_enabled', '1', 'boolean');
        Setting::set('pricing_programs.expedia_mobile_pct', '10', 'number');

        $quote = OtaPricingPrograms::quote(85);

        $this->assertEquals(85.0, $quote['booking']['published_price']);
        $this->assertEquals(104.94, $quote['expedia']['published_price']);
        $this->assertEquals(23.46, $quote['expedia']['required_modifier_pct']);
    }

    public function test_settings_endpoint_persists_the_programs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('settings.pricing-programs'), [
            'booking_genius_enabled' => true,
            'booking_genius_pct' => 15,
            'booking_mobile_enabled' => true,
            'booking_mobile_pct' => 10,
            'booking_preferred_enabled' => true,
            'expedia_member_enabled' => true,
            'expedia_member_pct' => 10,
            'expedia_mobile_enabled' => false,
            'expedia_mobile_pct' => 10,
        ])->assertRedirect();

        $this->assertTrue((bool) Setting::get('pricing_programs.booking_genius_enabled'));
        $this->assertSame(15.0, Setting::get('pricing_programs.booking_genius_pct'));
        $this->assertTrue((bool) Setting::get('pricing_programs.booking_preferred_enabled'));
    }
}
