<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_creates_cash_and_bank_accounts_with_zero_balance(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();

        $this->actingAs($admin)->post(route('finance.accounts.store'), [
            'name' => 'Arka e Restorantit', 'type' => 'cash', 'currency' => 'ALL',
        ])->assertRedirect()->assertSessionHas('success');

        // IBAN is a bank thing — silently dropped on a cash account…
        $this->actingAs($admin)->post(route('finance.accounts.store'), [
            'name' => 'Arka 2', 'type' => 'cash', 'currency' => 'EUR', 'iban' => 'AL123',
        ])->assertRedirect();
        $this->assertNull(FinanceAccount::where('name', 'Arka 2')->first()->iban);

        // …and kept on a bank account.
        $this->actingAs($admin)->post(route('finance.accounts.store'), [
            'name' => 'BKT', 'type' => 'bank', 'currency' => 'EUR', 'iban' => 'AL47212110090000000235698741',
        ])->assertRedirect();

        $bkt = FinanceAccount::where('name', 'BKT')->first();
        $this->assertSame('AL47212110090000000235698741', $bkt->iban);
        $this->assertTrue($bkt->is_active);
        $this->assertSame(0.0, $bkt->balance());
        $this->assertSame(0.0, FinanceAccount::where('name', 'Arka e Restorantit')->first()->balance());
    }

    public function test_duplicate_name_is_refused_with_a_clean_error(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();

        $this->actingAs($admin)->post(route('finance.accounts.store'), [
            'name' => 'Arka', 'type' => 'cash', 'currency' => 'EUR',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, FinanceAccount::where('name', 'Arka')->count());
    }

    public function test_only_manage_finance_settings_can_create_or_toggle(): void
    {
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->first();

        // Manager runs operations but does NOT own finance settings.
        foreach (['manager', 'receptionist'] as $r) {
            $user = $this->role($r);
            $this->actingAs($user)->post(route('finance.accounts.store'), [
                'name' => "X-{$r}", 'type' => 'cash', 'currency' => 'EUR',
            ])->assertForbidden();
            $this->actingAs($user)->put(route('finance.accounts.toggle', $arka))->assertForbidden();
        }
    }

    public function test_last_active_account_of_a_type_cannot_be_deactivated(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->first();

        // Only one active cash account: the auto-feed needs it — refuse.
        $this->actingAs($admin)->put(route('finance.accounts.toggle', $arka))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertTrue($arka->fresh()->is_active);

        // With a second cash box the first becomes deactivatable…
        FinanceAccount::create(['name' => 'Arka 2', 'type' => 'cash', 'currency' => 'EUR', 'is_active' => true]);
        $this->actingAs($admin)->put(route('finance.accounts.toggle', $arka))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse($arka->fresh()->is_active);

        // …and can be brought back.
        $this->actingAs($admin)->put(route('finance.accounts.toggle', $arka))->assertRedirect();
        $this->assertTrue($arka->fresh()->is_active);
    }

    public function test_deactivated_account_leaves_dropdowns_but_stays_on_the_management_page(): void
    {
        $this->withoutVite();
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $extra = FinanceAccount::create(['name' => 'Arka 2', 'type' => 'cash', 'currency' => 'EUR', 'is_active' => false]);

        // Money-moving screens (Payments) list only ACTIVE accounts…
        $this->actingAs($admin)->get(route('finance.payments'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('accounts', fn ($accounts) => collect($accounts)->every(fn ($a) => $a['name'] !== 'Arka 2')));

        // …while the management page still shows it (dimmed) with its history.
        $this->actingAs($admin)->get(route('finance.accounts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('accounts', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['name'] === 'Arka 2' && $a['is_active'] === false)));

        // Its ledger stays reachable directly too.
        $this->actingAs($admin)->get(route('finance.accounts', ['account_id' => $extra->id]))->assertOk();
    }

    public function test_accounts_page_converts_today_totals_and_does_not_count_internal_transfers(): void
    {
        $this->withoutVite();
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $cash = FinanceAccount::where('type', 'cash')->firstOrFail();
        $bank = FinanceAccount::where('type', 'bank')->firstOrFail();

        foreach ([
            ['direction' => 'in', 'account_id' => $cash->id, 'amount' => 100, 'paid_at' => now()],
            ['direction' => 'out', 'account_id' => $cash->id, 'amount' => 30, 'paid_at' => now()],
            ['direction' => 'transfer', 'account_id' => $cash->id, 'counter_account_id' => $bank->id, 'amount' => 20, 'paid_at' => now()],
            ['direction' => 'in', 'account_id' => $cash->id, 'amount' => 9870, 'currency' => 'ALL', 'fx_rate' => 98.7, 'paid_at' => now()],
            ['direction' => 'in', 'account_id' => $cash->id, 'amount' => 500, 'paid_at' => now()->subDay()],
        ] as $movement) {
            FinancePayment::create(array_merge([
                'currency' => 'EUR',
                'method' => 'cash',
                'source' => 'manual',
                'description' => 'Lëvizje testi',
            ], $movement));
        }

        $this->actingAs($admin)->get(route('finance.accounts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Accounts')
                ->where('todayNet', 170)
                ->where('ledger.0.delta', 100)
                ->where('ledger.0.balance', 650));
    }

    public function test_bad_currency_or_type_is_rejected(): void
    {
        $admin = $this->role('admin');

        $this->actingAs($admin)->post(route('finance.accounts.store'), [
            'name' => 'Kripto', 'type' => 'cash', 'currency' => 'BTC',
        ])->assertSessionHasErrors('currency');

        $this->actingAs($admin)->post(route('finance.accounts.store'), [
            'name' => 'Sirtar', 'type' => 'drawer', 'currency' => 'EUR',
        ])->assertSessionHasErrors('type');
    }
}
