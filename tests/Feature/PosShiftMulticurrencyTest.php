<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\PlatformSetting;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\PosShiftCurrency;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shift drawer can hold more than the base currency: foreign floats are
 * declared at opening, every currency that saw cash is counted at close, and
 * each foreign variance posts to THAT currency's POS account.
 */
class PosShiftMulticurrencyTest extends TestCase
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

    /** Opens a shift over HTTP with a declared 50 EUR float and returns it. */
    private function openShiftWithEuroFloat(User $admin): PosShift
    {
        $this->actingAs($admin)->post(route('pos.shift.open'), [
            'opening_float' => 1000,
            'currencies' => [['currency' => 'EUR', 'amount' => 50]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return PosShift::query()->sole();
    }

    /** One completed order paid with 10 EUR cash (frozen at 93.72). */
    private function sellForTenEuro(User $admin, PosShift $shift): void
    {
        $order = PosOrder::create([
            'status' => 'open',
            'total_amount' => 937.20,
            'created_by' => $admin->id,
            'pos_shift_id' => $shift->id,
        ]);
        $this->actingAs($admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 937.20, 'currency' => 'EUR', 'tendered_amount' => 10],
            ],
        ])->assertSessionHasNoErrors();
    }

    public function test_opening_declares_foreign_floats_and_rejects_unknown_currencies(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();

        $this->actingAs($admin)->post(route('pos.shift.open'), [
            'opening_float' => 1000,
            'currencies' => [['currency' => 'XXX', 'amount' => 10]],
        ])->assertSessionHasErrors('currencies.0.currency');

        // The base currency is not a "foreign" line — it IS opening_float.
        $this->actingAs($admin)->post(route('pos.shift.open'), [
            'opening_float' => 1000,
            'currencies' => [['currency' => 'ALL', 'amount' => 10]],
        ])->assertSessionHasErrors('currencies.0.currency');

        $shift = $this->openShiftWithEuroFloat($admin);
        $line = $shift->currencies()->sole();
        $this->assertSame('EUR', $line->currency);
        $this->assertSame(50.0, (float) $line->opening_amount);
    }

    public function test_exact_count_closes_with_zero_variance_and_no_extra_ledger_row(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();
        $shift = $this->openShiftWithEuroFloat($admin);
        $this->sellForTenEuro($admin, $shift);

        // The 10 EUR sits PHYSICALLY as euros: the base drawer holds only the
        // float, while the EUR line expects 60. Nothing is counted twice.
        $this->actingAs($admin)->post(route('pos.shift.close', $shift), [
            'counted_cash' => 1000,
            'counted_currencies' => [['currency' => 'EUR', 'counted' => 60]], // 50 float + 10 taken
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame(1000.0, (float) $shift->expected_cash);
        $this->assertSame(937.20, (float) $shift->cash_sales); // reporting keeps the full equivalent
        $this->assertSame(0.0, (float) $shift->over_short);

        $line = $shift->currencies()->sole();
        $this->assertSame(60.0, (float) $line->expected_amount);
        $this->assertSame(60.0, (float) $line->counted_amount);
        $this->assertSame(0.0, (float) $line->over_short);

        // A zero variance leaves no ledger adjustment for the EUR drawer.
        $this->assertSame(0, FinancePayment::query()
            ->where('sourceable_type', PosShiftCurrency::class)->count());
    }

    public function test_missing_euros_stay_on_the_shift_report_and_never_touch_the_accounts(): void
    {
        // Renato (2026-08-21): differences are REPORT-only — the shift line
        // carries the shortage for the manager; the ledger stays untouched.
        $admin = $this->admin();
        $this->lekBaseWithRates();
        $shift = $this->openShiftWithEuroFloat($admin);
        $this->sellForTenEuro($admin, $shift);

        $this->actingAs($admin)->post(route('pos.shift.close', $shift), [
            'counted_cash' => 1000,
            'counted_currencies' => [['currency' => 'EUR', 'counted' => 55]], // 5 EUR short
        ])->assertRedirect()->assertSessionHasNoErrors();

        $line = $shift->refresh()->currencies()->sole();
        $this->assertSame(-5.0, (float) $line->over_short);

        $this->assertSame(
            0,
            FinancePayment::query()
                ->where('sourceable_type', PosShiftCurrency::class)
                ->where('sourceable_id', $line->id)
                ->count(),
        );
    }

    public function test_close_refuses_until_every_currency_with_activity_is_counted(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();
        $shift = $this->openShiftWithEuroFloat($admin);

        // No counted_currencies at all → refused with the flash error.
        $this->actingAs($admin)->post(route('pos.shift.close', $shift), [
            'counted_cash' => 1000,
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame('open', $shift->fresh()->status);

        // A currency this shift never touched → refused too.
        $this->actingAs($admin)->post(route('pos.shift.close', $shift), [
            'counted_cash' => 1000,
            'counted_currencies' => [
                ['currency' => 'EUR', 'counted' => 50],
                ['currency' => 'USD', 'counted' => 5],
            ],
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame('open', $shift->fresh()->status);

        // A currency with sales but NO declared float still demands counting.
        $this->sellForTenEuro($admin, $shift);
        $this->actingAs($admin)->post(route('pos.shift.close', $shift), [
            'counted_cash' => 1000,
            'counted_currencies' => [['currency' => 'EUR', 'counted' => 60]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $shift->fresh()->status);
    }

    public function test_base_only_shift_closes_exactly_as_before(): void
    {
        $admin = $this->admin();
        $this->lekBaseWithRates();

        $this->actingAs($admin)->post(route('pos.shift.open'), [
            'opening_float' => 20,
        ])->assertSessionHasNoErrors();
        $shift = PosShift::query()->sole();

        $this->actingAs($admin)->post(route('pos.shift.close', $shift), [
            'counted_cash' => 20,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame(0.0, (float) $shift->over_short);
        $this->assertSame(0, $shift->currencies()->count());
        $this->assertSame(0, FinanceAccount::query()->where('currency', 'EUR')->count());
    }
}
