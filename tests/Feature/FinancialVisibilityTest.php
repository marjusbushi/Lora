<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FinancialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_bank_report_requires_bank_visibility_even_with_the_finance_sector(): void
    {
        // The audit's sharpest finding: the report bypassed the bank wall.
        // Manager holds view_reports_finance but NOT view_bank_accounts.
        $this->actingAs($this->userWithRole('manager'))
            ->get(route('reports.bankPayments'))->assertForbidden();

        $this->actingAs($this->userWithRole('admin'))
            ->get(route('reports.bankPayments'))->assertOk();
    }

    public function test_dashboard_money_is_stripped_server_side_for_the_desk(): void
    {
        // Hiding in Vue is not access control — the PAYLOAD must omit the money.
        $this->actingAs($this->userWithRole('receptionist'))
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permissions.view_financials', false)
                ->where('ownerPulse', null));

        $this->actingAs($this->userWithRole('manager'))
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permissions.view_financials', true)
                ->has('ownerPulse'));
    }

    public function test_reception_cannot_browse_invoices_bills_or_suppliers(): void
    {
        $receptionist = $this->userWithRole('receptionist');

        $this->actingAs($receptionist)->get(route('finance.invoices'))->assertForbidden();
        $this->actingAs($receptionist)->get(route('finance.bills'))->assertForbidden();
        $this->actingAs($receptionist)->get(route('finance.suppliers'))->assertForbidden();

        $manager = $this->userWithRole('manager');
        $this->actingAs($manager)->get(route('finance.invoices'))->assertOk();
        $this->actingAs($manager)->get(route('finance.bills'))->assertOk();
        $this->actingAs($manager)->get(route('finance.suppliers'))->assertOk();
    }

    public function test_reception_is_out_of_the_finance_module_but_the_manager_keeps_it(): void
    {
        // Renato (2026-08-18, after impersonating the role live): the desk no
        // longer sees the Financa module AT ALL — the 2026-08-17 "arka only"
        // narrowing is superseded. Checkout money flows through the folio
        // (reservations.payment, gated by update_reservations) — proven in
        // ReservationPaymentTest.
        $receptionist = $this->userWithRole('receptionist');

        $this->actingAs($receptionist)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($receptionist)->get(route('finance.payments'))->assertForbidden();

        // The pages themselves stay healthy for a role that kept the module.
        $manager = $this->userWithRole('manager');
        $this->actingAs($manager)->get(route('finance.index'))->assertOk();
        $this->actingAs($manager)->get(route('finance.payments'))->assertOk();
    }
}
