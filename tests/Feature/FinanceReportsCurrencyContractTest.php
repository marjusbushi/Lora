<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FinanceReportsCurrencyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_finance_report_endpoints_carry_the_currency_payload(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $routes = [
            'reports.shifts' => 'Reports/Shifts',
            'reports.payments' => 'Reports/Payments',
            'reports.vat' => 'Reports/Vat',
            'reports.discounts' => 'Reports/Discounts',
            'reports.departmentRevenue' => 'Reports/DepartmentRevenue',
        ];

        foreach ($routes as $name => $component) {
            $this->actingAs($admin)
                ->get(route($name))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component($component)
                    ->has('pricingCurrency')
                    ->has('baseToPricingRate'));
        }
    }
}
