<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MaintenanceDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_maintenance_mockup(): void
    {
        $this->get(route('maintenance.design'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_staff_can_open_maintenance_mockup(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('maintenance.design'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Maintenance/Design'));
    }
}
