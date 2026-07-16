<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\InventoryItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

        $this->actingAs($rec)->get(route('finance.bills.create'))->assertForbidden();

        $this->actingAs($rec)->post(route('finance.bills.store'), [
            'supplier_id' => $supplier->id, 'category' => 'Të tjera',
            'issue_date' => '2026-07-10', 'currency' => 'EUR', 'total' => 50,
        ])->assertForbidden();

        $this->actingAs($rec)->post(route('finance.bills.import-ai.analyze'), [
            'document' => UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4'),
        ])->assertForbidden();

        $bill = $this->lekBill($supplier);
        FinanceAccount::ensureDefaults();
        $this->actingAs($rec)->post(route('finance.bills.pay', $bill), [
            'account_id' => FinanceAccount::first()->id, 'amount' => 10, 'method' => 'cash',
        ])->assertForbidden();

        $this->actingAs($rec)->post(route('finance.suppliers.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($rec)->post(route('finance.bill-categories.store'), ['name' => 'Pajisje'])->assertForbidden();
    }

    public function test_bill_form_can_create_dynamic_categories_and_suppliers(): void
    {
        $this->withoutVite();
        $admin = $this->role('admin');

        $this->actingAs($admin)->from(route('finance.bills'))
            ->post(route('finance.bill-categories.store'), ['name' => 'Pajisje hoteli'])
            ->assertRedirect(route('finance.bills'))
            ->assertSessionHasNoErrors();

        $this->assertContains('Pajisje hoteli', Setting::get('financial.expense_categories'));

        $this->actingAs($admin)->from(route('finance.bills'))
            ->post(route('finance.bill-categories.store'), ['name' => '  PAJISJE HOTELI  '])
            ->assertRedirect(route('finance.bills'))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)->from(route('finance.bills'))
            ->post(route('finance.suppliers.store'), [
                'name' => 'Tekno Hotel',
                'category' => 'Pajisje hoteli',
                'payment_terms_days' => 15,
                'is_active' => true,
            ])->assertRedirect(route('finance.bills'))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('finance.bills'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('categories.6', 'Pajisje hoteli')
                ->where('suppliers.0.name', 'Tekno Hotel'));
    }

    public function test_bills_page_ships_rows_and_category_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00'));
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
                ->where('summary.open_total', 100)
                ->where('summary.open_count', 1)
                ->where('summary.supplier_count', 1)
                ->where('summary.overdue_count', 0)
                ->where('summary.due_soon_count', 0)
                ->has('priorities', 1)
                ->has('categories'));
    }

    public function test_bill_create_is_a_dedicated_page_with_document_options(): void
    {
        $this->withoutVite();
        $manager = $this->role('manager');
        $supplier = $this->supplier();

        $this->actingAs($manager)->get(route('finance.bills.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/BillCreate')
                ->where('suppliers.0.id', $supplier->id)
                ->where('suppliers.0.payment_terms_days', 14)
                ->has('categories')
                ->has('inventoryItems', 0)
                ->has('warehouses', 1)
                ->where('aiConfigured', false)
                ->where('openAiImport', false)
                ->where('can.manageBills', true));
    }

    public function test_ai_reads_and_matches_a_bill_without_creating_anything_before_confirmation(): void
    {
        $manager = $this->role('manager');
        $supplier = $this->supplier('EKO Market');
        InventoryItem::create([
            'name' => 'Ujë 0.5 L',
            'sku' => 'UJE-05',
            'type' => 'product',
            'unit' => 'piece',
            'average_cost' => 0.3,
            'is_active' => true,
        ]);

        config()->set('services.gemini.key', 'secret-test-key');
        config()->set('services.gemini.model', 'gemini-test-model');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => [
                            'name' => 'submit_purchase_invoice',
                            'args' => [
                                'supplier_name' => 'EKO Market',
                                'supplier_tax_id' => '',
                                'invoice_number' => 'INV-204',
                                'issue_date' => '2026-07-16',
                                'due_date' => '2026-07-30',
                                'currency' => 'EUR',
                                'category' => 'Ushqim & Pije',
                                'subtotal' => 16.67,
                                'tax_total' => 3.33,
                                'discount_total' => 0,
                                'grand_total' => 20,
                                'confidence' => 96,
                                'line_items' => [
                                    [
                                        'description' => 'Ujë 0.5 L', 'sku' => 'UJE-05', 'barcode' => '',
                                        'quantity' => 10, 'unit' => 'piece', 'item_type' => 'product',
                                        'line_total' => 5, 'confidence' => 99,
                                    ],
                                    [
                                        'description' => 'Detergjent hoteli', 'sku' => '', 'barcode' => '',
                                        'quantity' => 3, 'unit' => 'liter', 'item_type' => 'consumable',
                                        'line_total' => 11.67, 'confidence' => 94,
                                    ],
                                ],
                            ],
                        ],
                    ]]],
                ]],
            ]),
        ]);

        $response = $this->actingAs($manager)->post(route('finance.bills.import-ai.analyze'), [
            'document' => UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4 invoice test'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('supplier.match.id', $supplier->id)
            ->assertJsonPath('invoice.number', 'INV-204')
            ->assertJsonPath('invoice.grand_total', 20)
            ->assertJsonPath('invoice.line_costs_adjusted', true)
            ->assertJsonPath('items.0.match.name', 'Ujë 0.5 L')
            ->assertJsonPath('items.1.match', null)
            ->assertJsonPath('summary.matched_items', 1)
            ->assertJsonPath('summary.new_items', 1);

        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('inventory_items', 1);

        Http::assertSent(function ($request) {
            $schema = $request->data()['tools'][0]['function_declarations'][0]['parameters'] ?? [];
            $encodedSchema = json_encode($schema);

            return $request->hasHeader('x-goog-api-key', 'secret-test-key')
                && ! str_contains($request->url(), 'secret-test-key')
                && ! str_contains($encodedSchema, '"minimum"')
                && ! str_contains($encodedSchema, '"maximum"')
                && ! str_contains($encodedSchema, '"maxItems"');
        });
    }

    public function test_confirming_an_ai_bill_creates_missing_items_and_reuses_existing_ones(): void
    {
        $manager = $this->role('manager');
        $supplier = $this->supplier();
        Warehouse::ensureDefault();
        $warehouse = Warehouse::firstOrFail();
        $existing = InventoryItem::create([
            'name' => 'Ujë 0.5 L', 'sku' => 'UJE-05', 'type' => 'product', 'unit' => 'piece',
            'average_cost' => 0.3, 'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('finance.bills.store'), [
            'supplier_id' => $supplier->id,
            'number' => 'AI-204',
            'category' => 'Ushqim & Pije',
            'issue_date' => '2026-07-16',
            'currency' => 'EUR',
            'total' => 20,
            'receive_stock' => false,
            'items' => [
                [
                    'inventory_item_id' => $existing->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 10,
                    'unit_cost' => 0.6,
                ],
                [
                    'inventory_item_id' => null,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 2,
                    'unit_cost' => 7,
                    'new_item' => [
                        'name' => 'Detergjent hoteli',
                        'sku' => '',
                        'barcode' => '',
                        'category' => 'Ushqim & Pije',
                        'type' => 'consumable',
                        'unit' => 'liter',
                    ],
                ],
            ],
        ])->assertRedirect(route('finance.bills'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bills', 1);
        $this->assertDatabaseCount('bill_items', 2);
        $this->assertDatabaseCount('inventory_items', 2);
        $this->assertDatabaseHas('inventory_items', [
            'name' => 'Detergjent hoteli',
            'type' => 'consumable',
            'unit' => 'liter',
            'is_active' => true,
        ]);
        $this->assertSame(20.0, (float) Bill::firstOrFail()->total);
    }

    public function test_ai_import_rechecks_duplicates_when_the_bill_is_confirmed(): void
    {
        $manager = $this->role('manager');
        $supplier = $this->supplier();
        Warehouse::ensureDefault();
        $warehouse = Warehouse::firstOrFail();
        $existing = InventoryItem::create([
            'name' => 'Peshqir Banje', 'sku' => 'PESH-01', 'type' => 'product', 'unit' => 'piece',
            'average_cost' => 4, 'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('finance.bills.store'), [
            'supplier_id' => $supplier->id,
            'category' => 'Të tjera',
            'issue_date' => '2026-07-16',
            'currency' => 'EUR',
            'total' => 10,
            'items' => [[
                'inventory_item_id' => null,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
                'unit_cost' => 5,
                'new_item' => [
                    'name' => '  PESHQIR-BANJE ',
                    'type' => 'product',
                    'unit' => 'piece',
                ],
            ]],
        ])->assertRedirect(route('finance.bills'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('inventory_items', 1);
        $this->assertDatabaseHas('bill_items', ['inventory_item_id' => $existing->id]);
    }

    public function test_same_supplier_invoice_number_cannot_be_saved_twice(): void
    {
        $manager = $this->role('manager');
        $supplier = $this->supplier();
        $payload = [
            'supplier_id' => $supplier->id,
            'number' => 'INV-777',
            'category' => 'Të tjera',
            'issue_date' => '2026-07-16',
            'currency' => 'EUR',
            'total' => 10,
        ];

        $this->actingAs($manager)->post(route('finance.bills.store'), $payload)
            ->assertRedirect(route('finance.bills'))
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)->from(route('finance.bills.create'))
            ->post(route('finance.bills.store'), $payload + ['number' => 'inv-777'])
            ->assertRedirect(route('finance.bills.create'))
            ->assertSessionHasErrors('number');

        $this->assertDatabaseCount('bills', 1);
    }

    public function test_bills_page_filters_overdue_rows_by_supplier_and_category(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00'));
        $this->withoutVite();
        $manager = $this->role('manager');
        $overdue = $this->lekBill($this->supplier('Eco Market'));
        $overdue->update(['number' => 'EM-184', 'issue_date' => '2026-07-01', 'due_date' => '2026-07-12']);

        $future = $this->lekBill($this->supplier('Lavanderi Adriatik'), 4935);
        $future->update(['category' => 'Lavanderi', 'issue_date' => '2026-07-10', 'due_date' => '2026-07-16']);

        $this->actingAs($manager)->get(route('finance.bills', [
            'filter' => 'overdue',
            'category' => 'Ushqim & Pije',
            'search' => 'Eco',
        ]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.filter', 'overdue')
                ->where('filters.category', 'Ushqim & Pije')
                ->where('filters.search', 'Eco')
                ->where('bills.total', 1)
                ->where('bills.data.0.number', 'EM-184')
                ->where('summary.open_count', 2)
                ->where('summary.overdue_count', 1)
                ->where('summary.due_soon_count', 1)
                ->has('priorities', 2));
    }

    public function test_suppliers_page_ships_operational_summary_and_open_bills(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00'));
        $this->withoutVite();
        $manager = $this->role('manager');
        $supplier = $this->supplier('Eco Market');
        $bill = $this->lekBill($supplier);
        $bill->update(['number' => 'EM-184', 'issue_date' => '2026-07-01', 'due_date' => '2026-07-12']);

        $this->actingAs($manager)->get(route('finance.suppliers'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Suppliers')
                ->where('suppliers.0.name', 'Eco Market')
                ->where('suppliers.0.open_balance', 100)
                ->where('suppliers.0.overdue_balance', 100)
                ->where('suppliers.0.open_bills_count', 1)
                ->where('suppliers.0.open_bills.0.number', 'EM-184')
                ->where('suppliers.0.open_bills.0.is_overdue', true)
                ->where('summary.active_count', 1)
                ->where('summary.open_total', 100)
                ->where('summary.open_bill_count', 1)
                ->where('summary.overdue_total', 100)
                ->where('summary.overdue_supplier_count', 1)
                ->where('summary.ytd_total', 100));
    }
}
