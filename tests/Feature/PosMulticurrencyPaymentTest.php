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
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POS tenders in any active currency: the customer's money is recorded in its
 * own currency, lands in that currency's Arka/Banka (auto-created), while the
 * order and shift keep reconciling in the base currency.
 */
class PosMulticurrencyPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function lekBaseWithRates(): void
    {
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72, 'USD' => 1.14], 'json');
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
        // Legacy-style ticket: no item rows, subtotal falls back to total_amount.
        return PosOrder::create(['status' => 'open', 'total_amount' => $total, 'created_by' => $user->id]);
    }

    public function test_euro_cash_lands_in_the_euro_account_with_the_frozen_rate(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();
        $this->openShift($admin);
        $order = $this->openOrder($admin, 937.20);

        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 937.20, 'currency' => 'EUR', 'tendered_amount' => 10],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tender = PosOrderPayment::query()->sole();
        $this->assertSame('EUR', $tender->currency);
        $this->assertSame(10.0, (float) $tender->tendered_amount);
        $this->assertSame(93.72, (float) $tender->exchange_rate);
        $this->assertSame(937.20, (float) $tender->amount);

        $euroArka = FinanceAccount::query()->where('type', 'cash')->where('currency', 'EUR')->sole();
        $this->assertSame('Arka EUR', $euroArka->name);

        $ledger = FinancePayment::query()->where('sourceable_type', PosOrderPayment::class)->sole();
        $this->assertSame($euroArka->id, $ledger->account_id);
        $this->assertSame('EUR', $ledger->currency);
        $this->assertSame(10.0, (float) $ledger->amount);
        $this->assertSame(round(1 / 93.72, 6), (float) $ledger->fx_rate);
        $this->assertSame(937.20, (float) $ledger->amount_base);

        // The request lifecycle cleared the tenant context — restore it so
        // balance() resolves the base currency the way a live request would.
        app(TenantContext::class)->set(Tenant::query()->sole());
        $this->assertSame(10.0, $euroArka->balance());
    }

    public function test_split_base_cash_and_foreign_card_reconcile_exactly(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();
        $this->openShift($admin);
        $order = $this->openOrder($admin, 1000.00);

        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 500.00],
                // 5.34 EUR × 93.72 = 500.47 — the sub-cent FX residual is
                // absorbed so the base amounts reconcile with the total.
                ['method' => 'card', 'amount' => 500.00, 'currency' => 'EUR', 'tendered_amount' => 5.34],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tenders = PosOrderPayment::query()->orderBy('id')->get();
        $this->assertNull($tenders[0]->currency);
        $this->assertSame(500.0, (float) $tenders[0]->amount);
        $this->assertSame('EUR', $tenders[1]->currency);
        $this->assertSame(5.34, (float) $tenders[1]->tendered_amount);
        $this->assertSame(500.0, (float) $tenders[1]->amount);
        $this->assertSame(1000.0, (float) $tenders->sum('amount'));

        $euroBank = FinanceAccount::query()->where('type', 'bank')->where('currency', 'EUR')->sole();
        $this->assertSame('Banka EUR', $euroBank->name);
        app(TenantContext::class)->set(Tenant::query()->sole());
        $this->assertSame(5.34, $euroBank->balance());
    }

    public function test_disabled_currency_is_rejected(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();
        Setting::set('currencies.disabled', ['USD'], 'json');
        $this->openShift($admin);
        $order = $this->openOrder($admin, 114.00);

        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 114.00, 'currency' => 'USD', 'tendered_amount' => 100],
            ],
        ])->assertSessionHasErrors('payments.0.currency');

        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_missing_rate_blocks_the_sale_cleanly(): void
    {
        $admin = $this->admin();
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        // No platform rates, no manual fallback: USD cannot be priced.
        $this->openShift($admin);
        $order = $this->openOrder($admin, 114.00);

        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 114.00, 'currency' => 'USD', 'tendered_amount' => 100],
            ],
        ])->assertSessionHasErrors('payments');

        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, PosOrderPayment::query()->count());
    }

    public function test_base_currency_sale_is_untouched_by_the_feature(): void
    {
        $admin = $this->admin();
        $this->openShift($admin);
        $order = $this->openOrder($admin, 25.50);

        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tender = PosOrderPayment::query()->sole();
        $this->assertNull($tender->currency);
        $this->assertNull($tender->tendered_amount);
        $this->assertSame(25.50, (float) $tender->amount);

        $ledger = FinancePayment::query()->where('sourceable_type', PosOrderPayment::class)->sole();
        $this->assertSame('Arka', $ledger->account?->name);
        $this->assertNull($ledger->fx_rate);
    }
}
