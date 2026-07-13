<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceBillsTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function supplier(string $name = 'EKO Market'): Supplier
    {
        return Supplier::create(['name' => $name, 'category' => 'Ushqim & Pije', 'payment_terms_days' => 14]);
    }

    private function lekBill(Supplier $s, float $totalLek = 9870, float $fx = 98.7): Bill
    {
        return Bill::create([
            'supplier_id' => $s->id, 'category' => 'Ushqim & Pije',
            'issue_date' => '2026-07-10', 'due_date' => '2026-07-24',
            'currency' => 'ALL', 'fx_rate' => $fx, 'total' => $totalLek, 'status' => 'open',
        ]);
    }

    public function test_lek_bill_freezes_its_fx_forever(): void
    {
        $bill = $this->lekBill($this->supplier()); // 9870 L @ 98.7 => 100 €
        $this->assertSame(100.0, (float) $bill->total_base);

        // today's rate changes — the old bill must NOT move
        Setting::set('financial.fx_all_per_eur', 120, 'number');
        Setting::set('currencies.rates', ['ALL' => 120], 'json');
        $this->assertSame(100.0, (float) $bill->fresh()->total_base);
    }

    public function test_partial_payment_transitions_and_exact_remainder(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->first();
        $bill = $this->lekBill($this->supplier()); // 100 € base

        // partial: 4935 L = 50 €
        $this->actingAs($admin)->post(route('finance.bills.pay', $bill), [
            'account_id' => $arka->id, 'amount' => 4935, 'method' => 'cash',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('partial', $bill->status);
        $this->assertSame(50.0, $bill->remainingBase());

        // the rest: pays off exactly, no rounding drift
        $this->actingAs($admin)->post(route('finance.bills.pay', $bill), [
            'account_id' => $arka->id, 'amount' => 4935, 'method' => 'cash',
        ])->assertRedirect();

        $bill->refresh();
        $this->assertSame('paid', $bill->status);
        $this->assertSame(0.0, $bill->remainingBase());
    }

    public function test_paying_moves_account_and_supplier_balance_together_and_blocks_overpay(): void
    {
        $admin = $this->role('admin');
        FinanceAccount::ensureDefaults();
        $arka = FinanceAccount::where('type', 'cash')->first();
        $supplier = $this->supplier();
        $bill = $this->lekBill($supplier);
        $this->assertSame(100.0, $supplier->openBalanceBase());

        $this->actingAs($admin)->post(route('finance.bills.pay', $bill), [
            'account_id' => $arka->id, 'amount' => 9870, 'method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(-100.0, $arka->balance()); // cash out (ledger truth)
        $this->assertSame(0.0, $supplier->fresh()->openBalanceBase());

        // overpaying a PAID bill is refused with a clean Albanian error
        $this->actingAs($admin)->post(route('finance.bills.pay', $bill), [
            'account_id' => $arka->id, 'amount' => 100, 'method' => 'cash',
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, FinancePayment::where('bill_id', $bill->id)->count());
    }

    public function test_supplier_with_open_bills_cannot_be_removed(): void
    {
        $admin = $this->role('admin');
        $supplier = $this->supplier();
        $this->lekBill($supplier);

        $this->actingAs($admin)->delete(route('finance.suppliers.destroy', $supplier))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull(Supplier::find($supplier->id));

        // with only PAID history it deactivates (history preserved)…
        $supplier->bills()->update(['status' => 'paid']);
        $this->actingAs($admin)->delete(route('finance.suppliers.destroy', $supplier))->assertRedirect();
        $this->assertFalse((bool) $supplier->fresh()->is_active);

        // …and a supplier with no bills at all deletes cleanly
        $empty = $this->supplier('Furra Saranda');
        $this->actingAs($admin)->delete(route('finance.suppliers.destroy', $empty))->assertRedirect();
        $this->assertNull(Supplier::find($empty->id));
    }

    public function test_receptionist_cannot_manage_bills_or_suppliers(): void
    {
        $rec = $this->role('receptionist');
        $supplier = $this->supplier();

        $this->actingAs($rec)->post(route('finance.bills.store'), [
            'supplier_id' => $supplier->id, 'category' => 'Të tjera',
            'issue_date' => '2026-07-10', 'currency' => 'EUR', 'total' => 50,
        ])->assertForbidden();

        $bill = $this->lekBill($supplier);
        FinanceAccount::ensureDefaults();
        $this->actingAs($rec)->post(route('finance.bills.pay', $bill), [
            'account_id' => FinanceAccount::first()->id, 'amount' => 10, 'method' => 'cash',
        ])->assertForbidden();

        $this->actingAs($rec)->post(route('finance.suppliers.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_bills_page_ships_rows_and_category_totals(): void
    {
        $this->withoutVite();
        $manager = $this->role('manager');
        $bill = $this->lekBill($this->supplier());
        $bill->update(['issue_date' => now()->toDateString()]); // brenda muajit aktual

        $this->actingAs($manager)->get(route('finance.bills'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Bills')
                ->where('bills.data.0.remaining_base', 100)
                ->where('byCategory.Ushqim & Pije', 100)
                ->has('suppliers')
                ->has('categories'));
    }
}
