<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\PlatformSetting;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Pagesat në Bankë" — the statement-reconciliation report: only movements
 * that touched a bank account, summarized per account in ITS currency.
 */
class BankPaymentsReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');

        PosShift::create([
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_float' => 0,
            'opened_at' => now(),
        ]);
    }

    private function completeOrder(array $payments): PosOrder
    {
        $order = PosOrder::create([
            'status' => 'open',
            'total_amount' => collect($payments)->sum('amount'),
            'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'payments' => $payments,
        ])->assertSessionHasNoErrors();

        return $order;
    }

    public function test_report_shows_only_bank_movements_summarized_per_account(): void
    {
        // Base card → base Banka; euro card (manual rate) → the EUR bank
        // account; cash → Arka and must NOT appear in this report.
        $this->completeOrder([['method' => 'card', 'amount' => 500]]);
        $this->completeOrder([
            ['method' => 'card', 'amount' => 937.20, 'currency' => 'EUR', 'tendered_amount' => 10],
        ]);
        $this->completeOrder([['method' => 'cash', 'amount' => 300]]);

        $date = now()->toDateString();
        $this->actingAs($this->admin)
            ->get(route('reports.bankPayments', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/BankPayments')
                ->has('rows', 2)
                ->has('accounts', 2)
                ->where('totals.in_base', 1437.20)
                ->where('totals.out_base', 0)
                ->where('totals.net_base', 1437.20));

        // The EUR account summarizes in EUR (10.00), the base account in ALL.
        $response = $this->actingAs($this->admin)
            ->get(route('reports.bankPayments', ['from' => $date, 'to' => $date]));
        $accounts = collect($response->viewData('page')['props']['accounts']);
        $eur = $accounts->firstWhere('currency', 'EUR');
        $base = $accounts->firstWhere('currency', 'ALL');
        $this->assertSame(10.0, (float) $eur['in']);
        $this->assertSame(10.0, (float) $eur['net']);
        $this->assertSame(500.0, (float) $base['in']);
    }

    public function test_account_filter_and_period_bounds_limit_the_rows(): void
    {
        $this->completeOrder([['method' => 'card', 'amount' => 500]]);
        $this->completeOrder([
            ['method' => 'card', 'amount' => 937.20, 'currency' => 'EUR', 'tendered_amount' => 10],
        ]);

        $date = now()->toDateString();
        $eurBank = FinanceAccount::query()->where('type', 'bank')->where('currency', 'EUR')->sole();

        // Filtered to the EUR bank: one row, one account card, EUR totals only.
        $this->actingAs($this->admin)
            ->get(route('reports.bankPayments', ['from' => $date, 'to' => $date, 'account_id' => $eurBank->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->has('accounts', 1)
                ->where('accounts.0.currency', 'EUR')
                ->where('totals.in_base', 937.20));

        // A period before the payments existed → empty.
        $this->actingAs($this->admin)
            ->get(route('reports.bankPayments', [
                'from' => now()->subDays(10)->toDateString(),
                'to' => now()->subDays(9)->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 0)
                ->where('totals.net_base', 0));
    }

    public function test_refunded_card_order_shows_the_outgoing_movement(): void
    {
        $order = $this->completeOrder([['method' => 'card', 'amount' => 500]]);

        $this->actingAs($this->admin)->post(route('pos.refund', $order), [
            'reason' => 'Porosi e kthyer nga klienti',
        ])->assertSessionHasNoErrors();

        $date = now()->toDateString();
        $this->actingAs($this->admin)
            ->get(route('reports.bankPayments', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('totals.in_base', 500)
                ->where('totals.out_base', 500)
                ->where('totals.net_base', 0));
    }
}
