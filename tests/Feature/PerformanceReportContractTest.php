<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PerformanceReportContractTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_payload_carries_currency_keys_and_previous_year(): void
    {
        $this->actingAs($this->admin())
            ->get(route('reports.performance'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Performance')
                ->has('pricingCurrency')
                ->has('baseToPricingRate')
                ->has('analytics.previous_year'));
    }

    public function test_invalid_date_filters_are_rejected_not_500(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('reports.performance'))
            ->get(route('reports.performance', ['from' => '2026-07-31', 'to' => '2026-07-01']))
            ->assertRedirect(route('reports.performance'))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)->from(route('reports.performance'))
            ->get(route('reports.performance', ['from' => 'not-a-date', 'to' => '2026-07-31']))
            ->assertRedirect(route('reports.performance'))
            ->assertSessionHasErrors('from');
    }
}
