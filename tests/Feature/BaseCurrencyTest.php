<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BaseCurrency;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_and_finance_amounts_inherit_the_tenant_base_currency(): void
    {
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());

        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('name', 'Arka')->firstOrFail();

        $this->assertSame('ALL', BaseCurrency::code());
        $this->assertSame('ALL', $arka->currency);

        $payment = FinancePayment::create([
            'direction' => 'in',
            'account_id' => $arka->id,
            'amount' => 12_000,
            'currency' => 'ALL',
            'method' => 'cash',
            'source' => 'manual',
            'description' => 'Shitje në monedhën bazë',
            'paid_at' => now(),
        ]);

        $this->assertSame('12000.00', $payment->amount_base);
        $this->assertSame(12_000.0, $arka->fresh()->balance());
    }

    public function test_foreign_bill_is_converted_to_the_tenant_base_currency_with_a_frozen_rate(): void
    {
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        $supplier = Supplier::create(['name' => 'Furnitor EUR']);

        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'category' => 'Të tjera',
            'issue_date' => now()->toDateString(),
            'currency' => 'EUR',
            'fx_rate' => 0.01,
            'total' => 100,
            'status' => 'open',
        ]);

        $this->assertSame('10000.00', $bill->total_base);
        $this->assertSame('0.010000', $bill->fx_rate);
    }

    public function test_base_currency_change_is_blocked_after_financial_activity(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        FinanceAccount::ensureDefaults();

        FinancePayment::create([
            'direction' => 'in',
            'account_id' => FinanceAccount::where('name', 'Arka')->value('id'),
            'amount' => 25,
            'currency' => 'EUR',
            'method' => 'cash',
            'source' => 'manual',
            'description' => 'Aktivitet ekzistues',
            'paid_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        BaseCurrency::assertCanChange($tenant, 'ALL');
    }

    public function test_settings_shows_and_locks_the_authoritative_tenant_currency(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        FinanceAccount::ensureDefaults();
        FinancePayment::create([
            'direction' => 'in',
            'account_id' => FinanceAccount::where('name', 'Arka')->value('id'),
            'amount' => 25,
            'currency' => 'EUR',
            'method' => 'cash',
            'source' => 'manual',
            'description' => 'Aktivitet ekzistues',
            'paid_at' => now(),
        ]);
        Setting::set('hotel.currency', 'ALL'); // stale legacy setting

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.hotel.currency', 'EUR')
                ->where('settings.hotel.base_currency_locked', true));
    }
}
