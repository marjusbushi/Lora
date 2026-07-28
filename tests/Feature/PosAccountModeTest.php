<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\PlatformSetting;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinanceLedger;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-hotel POS account routing: shared (default — bar money joins the hotel
 * Arka/Banka), split_cash (bar cash gets its own drawer), split_all (bar cash
 * AND cards get their own accounts). One setting, one routing point; folio
 * payments and history are never touched by the mode.
 */
class PosAccountModeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** HTTP requests clear the tenant context; restore it before settings/balances. */
    private function restoreTenant(): void
    {
        app(TenantContext::class)->set(Tenant::query()->sole());
    }

    private function openShift(User $user): PosShift
    {
        return PosShift::create([
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_float' => 0,
        ]);
    }

    private function openOrder(User $user, float $total): PosOrder
    {
        return PosOrder::create(['status' => 'open', 'total_amount' => $total, 'created_by' => $user->id]);
    }

    private function completeCash(User $user, float $total): void
    {
        $order = $this->openOrder($user, $total);
        $this->actingAs($user)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->restoreTenant();
    }

    public function test_default_mode_keeps_pos_money_in_the_shared_accounts(): void
    {
        $admin = $this->admin();
        $this->openShift($admin);
        $this->completeCash($admin, 25.00);

        $ledger = FinancePayment::query()->where('sourceable_type', PosOrderPayment::class)->sole();
        $this->assertSame('Arka', $ledger->account->name);
        $this->assertSame('general', $ledger->account->scope);
        $this->assertSame(0, FinanceAccount::query()->where('scope', 'pos')->count());
    }

    public function test_split_cash_routes_pos_cash_to_the_bar_drawer_but_cards_to_the_shared_bank(): void
    {
        $admin = $this->admin();
        Setting::set('finance.pos_account_mode', 'split_cash', 'text');
        $this->openShift($admin);
        $this->completeCash($admin, 25.00);

        $cardOrder = $this->openOrder($admin, 40.00);
        $this->actingAs($admin)->post(route('pos.complete', $cardOrder), ['payment_method' => 'card'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->restoreTenant();

        $barDrawer = FinanceAccount::query()->where('scope', 'pos')->sole();
        $this->assertSame('Arka Bar/Restorant', $barDrawer->name);
        $this->assertSame('cash', $barDrawer->type);
        $this->assertSame(25.00, $barDrawer->balance());

        $cardLedger = FinancePayment::query()->where('method', 'card')->sole();
        $this->assertSame('Banka', $cardLedger->account->name);
        $this->assertSame('general', $cardLedger->account->scope);
    }

    public function test_split_all_routes_pos_cards_to_the_bar_bank(): void
    {
        $admin = $this->admin();
        Setting::set('finance.pos_account_mode', 'split_all', 'text');
        $this->openShift($admin);

        $order = $this->openOrder($admin, 40.00);
        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'card'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->restoreTenant();

        $barBank = FinanceAccount::query()->where('scope', 'pos')->sole();
        $this->assertSame('Banka Bar/Restorant', $barBank->name);
        $this->assertSame('bank', $barBank->type);
        $this->assertSame(40.00, $barBank->balance());
    }

    public function test_foreign_cash_in_split_mode_gets_its_own_bar_drawer(): void
    {
        $admin = $this->admin();
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        $this->restoreTenant();
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        Setting::set('finance.pos_account_mode', 'split_cash', 'text');
        $this->openShift($admin);

        $order = $this->openOrder($admin, 937.20);
        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 937.20, 'currency' => 'EUR', 'tendered_amount' => 10],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->restoreTenant();

        $barEuroDrawer = FinanceAccount::query()->where('scope', 'pos')->sole();
        $this->assertSame('Arka Bar/Restorant EUR', $barEuroDrawer->name);
        $this->assertSame('EUR', $barEuroDrawer->currency);
        $this->assertSame(10.0, $barEuroDrawer->balance());
    }

    public function test_refund_leaves_the_bar_drawer(): void
    {
        $admin = $this->admin();
        Setting::set('finance.pos_account_mode', 'split_cash', 'text');
        $this->openShift($admin);
        $order = $this->openOrder($admin, 25.00);
        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($admin)->post(route('pos.refund', $order), ['reason' => 'Klienti u pendua'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->restoreTenant();

        $barDrawer = FinanceAccount::query()->where('scope', 'pos')->sole();
        $this->assertSame('Arka Bar/Restorant', $barDrawer->name);
        $this->assertSame(0.0, $barDrawer->balance());
        $this->assertSame(2, FinancePayment::query()->where('account_id', $barDrawer->id)->count());
    }

    public function test_shift_difference_posts_to_the_bar_drawer(): void
    {
        $admin = $this->admin();
        Setting::set('finance.pos_account_mode', 'split_cash', 'text');
        $shift = $this->openShift($admin);
        $shift->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $admin->id,
            'counted_cash' => 50.00,
        ])->save();

        app(FinanceLedger::class)->recordShiftClose($shift->fresh());

        $ledger = FinancePayment::query()->where('sourceable_type', PosShift::class)->sole();
        $this->assertSame('Arka Bar/Restorant', $ledger->account->name);
        $this->assertSame('pos', $ledger->account->scope);
    }

    public function test_switching_mode_never_moves_history(): void
    {
        $admin = $this->admin();
        $this->openShift($admin);
        $this->completeCash($admin, 25.00);

        Setting::set('finance.pos_account_mode', 'split_cash', 'text');
        $this->completeCash($admin, 40.00);

        $sharedArka = FinanceAccount::query()->where('scope', 'general')->where('type', 'cash')->sole();
        $barDrawer = FinanceAccount::query()->where('scope', 'pos')->sole();
        $this->assertSame(25.00, $sharedArka->balance());
        $this->assertSame(40.00, $barDrawer->balance());
    }

    public function test_admin_switches_the_mode_from_the_accounts_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('finance.accounts.pos-mode'), ['mode' => 'split_cash'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->restoreTenant();
        $this->assertSame('split_cash', FinanceLedger::posAccountMode());

        $this->actingAs($admin)->put(route('finance.accounts.pos-mode'), ['mode' => 'invalid'])
            ->assertSessionHasErrors('mode');
    }

    public function test_reception_money_ignores_the_pos_mode(): void
    {
        $this->admin();
        Setting::set('finance.pos_account_mode', 'split_all', 'text');

        // Folio payments and manual movements resolve accounts WITHOUT the pos
        // flag — they must land in the hotel accounts in every mode.
        $this->assertSame('Arka', FinanceLedger::accountFor('cash')->name);
        $this->assertSame('Banka', FinanceLedger::accountFor('card')->name);
    }
}
