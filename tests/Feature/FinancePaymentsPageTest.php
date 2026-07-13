<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancePaymentsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function payment(array $attributes): FinancePayment
    {
        return FinancePayment::create(array_merge([
            'direction' => 'in',
            'account_id' => FinanceAccount::where('type', 'cash')->firstOrFail()->id,
            'amount' => 100,
            'currency' => 'EUR',
            'method' => 'cash',
            'source' => 'manual',
            'description' => 'Pagesë testi',
            'paid_at' => now(),
        ], $attributes));
    }

    public function test_payment_page_filters_rows_and_keeps_period_summary_stable(): void
    {
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00'));
        $admin = $this->admin();
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->firstOrFail();
        $bank = FinanceAccount::where('type', 'bank')->firstOrFail();

        $this->payment(['amount' => 100, 'source' => 'auto', 'description' => 'Pagesë folio — rezervimi #12']);
        $this->payment(['direction' => 'out', 'amount' => 30, 'description' => 'Blerje detergjentësh']);
        $this->payment([
            'direction' => 'transfer', 'account_id' => $arka->id, 'counter_account_id' => $bank->id,
            'amount' => 20, 'method' => 'bank', 'description' => 'Depozitim në bankë',
        ]);
        $this->payment(['amount' => 500, 'paid_at' => now()->subDays(60), 'description' => 'Pagesë e vjetër']);

        $period = ['date_from' => '2026-07-13', 'date_to' => '2026-07-13'];
        $this->actingAs($admin)->get(route('finance.payments', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Payments')
                ->where('summary.income', 100)
                ->where('summary.expenses', 30)
                ->where('summary.net', 70)
                ->where('summary.transfers', 1)
                ->where('payments.total', 3)
                ->where('filters.per_page', 20));

        $this->actingAs($admin)->get(route('finance.payments', array_merge($period, ['direction' => 'out'])))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.income', 100)
                ->where('summary.expenses', 30)
                ->where('payments.total', 1)
                ->where('payments.data.0.direction', 'out'));

        $this->actingAs($admin)->get(route('finance.payments', array_merge($period, ['query' => 'detergjent'])))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payments.total', 1)
                ->where('payments.data.0.description', 'Blerje detergjentësh'));
    }

    public function test_payment_export_uses_the_active_filters(): void
    {
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00'));
        $admin = $this->admin();
        FinanceAccount::ensureDefaults();

        $this->payment(['amount' => 90, 'description' => 'Arkëtim që nuk eksportohet']);
        $this->payment(['direction' => 'out', 'amount' => 25, 'description' => 'Dalje për eksport']);

        $response = $this->actingAs($admin)->get(route('finance.payments.export', [
            'direction' => 'out',
            'date_from' => '2026-07-13',
            'date_to' => '2026-07-13',
        ]));

        $response->assertOk()->assertDownload('pagesat-2026-07-13.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Dalje për eksport', $content);
        $this->assertStringNotContainsString('Arkëtim që nuk eksportohet', $content);
    }
}
