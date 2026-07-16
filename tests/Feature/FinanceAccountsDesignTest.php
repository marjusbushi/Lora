<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FinanceAccountsDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_finance_accounts_mockup(): void
    {
        $this->get(route('finance.accounts.design'))->assertRedirect(route('login'));
    }

    public function test_authorized_staff_can_open_finance_accounts_mockup(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->withoutVite()
            ->actingAs($admin)
            ->get(route('finance.accounts.design'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Finance/AccountsDesign'));
    }
}
