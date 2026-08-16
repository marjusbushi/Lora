<?php

namespace Tests\Feature;

use App\Models\HotelFaq;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelFaqTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('admin');
    }

    public function test_admin_can_create_update_and_delete_faq(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.faqs.store'), [
                'question' => 'A ka parkim?',
                'answer' => 'Po, falas para hotelit.',
            ])->assertRedirect();

        $faq = HotelFaq::query()->sole();
        $this->assertSame('A ka parkim?', $faq->question);
        $this->assertTrue($faq->is_active);

        $this->actingAs($this->admin)
            ->put(route('settings.faqs.update', $faq), [
                'question' => 'A ka parkim privat?',
                'answer' => 'Po, falas.',
                'is_active' => false,
            ])->assertRedirect();

        $this->assertDatabaseHas('hotel_faqs', ['id' => $faq->id, 'question' => 'A ka parkim privat?', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->delete(route('settings.faqs.destroy', $faq))
            ->assertRedirect();

        $this->assertDatabaseMissing('hotel_faqs', ['id' => $faq->id]);
    }

    public function test_cross_tenant_faq_is_404(): void
    {
        // FAQ e një tenant-i TJETËR — binding-u i skopuar duhet ta kthejë 404.
        $other = Tenant::factory()->create();
        app(TenantContext::class)->set($other);
        $foreign = HotelFaq::create(['question' => 'E huaja', 'answer' => 'Sekret']);
        app(TenantContext::class)->set($this->tenant);

        $this->actingAs($this->admin)
            ->put('/pms/settings/faqs/'.$foreign->id, [
                'question' => 'Hack', 'answer' => 'Hack', 'is_active' => true,
            ])->assertNotFound();

        app(TenantContext::class)->set($other);
        $this->assertDatabaseHas('hotel_faqs', ['id' => $foreign->id, 'question' => 'E huaja']);
    }
}
