<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Capital deposits/withdrawals on Arka & Banka: admin or the finance role
 * only, tagged with `movement` on the shared ledger.
 */
class FinanceMovementsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function arka(): FinanceAccount
    {
        FinanceAccount::ensureDefaults();

        return FinanceAccount::query()->where('type', 'cash')->firstOrFail();
    }

    public function test_admin_records_a_deposit_that_raises_the_balance(): void
    {
        $admin = $this->user('admin');
        $arka = $this->arka();

        $this->actingAs($admin)->post(route('finance.movements.store'), [
            'movement' => 'deposit',
            'account_id' => $arka->id,
            'amount' => 500,
            'currency' => $arka->currency,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $row = FinancePayment::query()->where('movement', 'deposit')->sole();
        $this->assertSame('in', $row->direction);
        $this->assertSame('Depozitim', $row->description);
        $this->assertSame(500.0, (float) $row->amount);
        $this->assertSame(500.0, (float) $arka->fresh()->balance());
    }

    public function test_admin_records_a_withdrawal_that_lowers_the_balance(): void
    {
        $admin = $this->user('admin');
        $arka = $this->arka();

        $this->actingAs($admin)->post(route('finance.movements.store'), [
            'movement' => 'deposit',
            'account_id' => $arka->id,
            'amount' => 300,
            'currency' => $arka->currency,
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('finance.movements.store'), [
            'movement' => 'withdrawal',
            'account_id' => $arka->id,
            'amount' => 120,
            'currency' => $arka->currency,
            'description' => 'Tërheqje e pronarit',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $withdrawal = FinancePayment::query()->where('movement', 'withdrawal')->sole();
        $this->assertSame('out', $withdrawal->direction);
        $this->assertSame('Tërheqje e pronarit', $withdrawal->description);
        $this->assertSame(180.0, (float) $arka->fresh()->balance());
    }

    public function test_finance_role_can_deposit_but_not_withdraw(): void
    {
        $finance = $this->user('finance');
        $arka = $this->arka();

        $this->actingAs($finance)->post(route('finance.movements.store'), [
            'movement' => 'deposit',
            'account_id' => $arka->id,
            'amount' => 250,
            'currency' => $arka->currency,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($finance)->post(route('finance.movements.store'), [
            'movement' => 'withdrawal',
            'account_id' => $arka->id,
            'amount' => 50,
            'currency' => $arka->currency,
        ])->assertForbidden();

        $this->assertSame(0, FinancePayment::query()->where('movement', 'withdrawal')->count());
        $this->assertSame(250.0, (float) $arka->fresh()->balance());

        // The report stays readable for the finance role.
        $this->actingAs($finance)->get(route('finance.movements'))->assertOk();
    }

    public function test_manager_and_receptionist_cannot_move_capital(): void
    {
        $arka = $this->arka();

        foreach (['manager', 'receptionist'] as $role) {
            $this->seed(RolePermissionSeeder::class);
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)->post(route('finance.movements.store'), [
                'movement' => 'withdrawal',
                'account_id' => $arka->id,
                'amount' => 50,
                'currency' => $arka->currency,
            ])->assertForbidden();
        }

        $this->assertSame(0, FinancePayment::query()->whereNotNull('movement')->count());
    }

    public function test_invalid_movement_type_is_rejected(): void
    {
        $admin = $this->user('admin');
        $arka = $this->arka();

        $this->actingAs($admin)->post(route('finance.movements.store'), [
            'movement' => 'loan',
            'account_id' => $arka->id,
            'amount' => 50,
            'currency' => $arka->currency,
        ])->assertSessionHasErrors('movement');
    }

    public function test_movements_report_shows_totals_and_respects_filters(): void
    {
        $admin = $this->user('admin');
        $arka = $this->arka();

        foreach ([['deposit', 500], ['deposit', 300], ['withdrawal', 120]] as [$kind, $amount]) {
            $this->actingAs($admin)->post(route('finance.movements.store'), [
                'movement' => $kind,
                'account_id' => $arka->id,
                'amount' => $amount,
                'currency' => $arka->currency,
            ])->assertRedirect();
        }
        // An ordinary payment must never appear in the capital report.
        $this->actingAs($admin)->post(route('finance.payments.store'), [
            'direction' => 'in', 'account_id' => $arka->id, 'amount' => 90,
            'currency' => $arka->currency, 'method' => 'cash', 'description' => 'Arkëtim',
        ])->assertRedirect();

        $props = $this->actingAs($admin)->get(route('finance.movements'))
            ->assertOk()->viewData('page')['props'];
        $this->assertCount(3, $props['rows']);
        $this->assertSame(800.0, $props['totals']['deposits']);
        $this->assertSame(120.0, $props['totals']['withdrawals']);
        $this->assertSame(680.0, $props['totals']['net']);

        $filtered = $this->actingAs($admin)->get(route('finance.movements', ['movement' => 'withdrawal']))
            ->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame(0.0, $filtered['totals']['deposits']);
        $this->assertSame(120.0, $filtered['totals']['withdrawals']);
    }

    public function test_movements_report_is_forbidden_without_the_permission(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->get(route('finance.movements'))->assertForbidden();
    }

    public function test_movement_rows_do_not_mix_into_ordinary_payment_flows(): void
    {
        $admin = $this->user('admin');
        $arka = $this->arka();

        // An ordinary manual payment has no movement tag.
        $this->actingAs($admin)->post(route('finance.payments.store'), [
            'direction' => 'in',
            'account_id' => $arka->id,
            'amount' => 90,
            'currency' => $arka->currency,
            'method' => 'cash',
            'description' => 'Arkëtim i thjeshtë',
        ])->assertRedirect();

        $this->assertNull(FinancePayment::query()->where('description', 'Arkëtim i thjeshtë')->sole()->movement);
    }
}
