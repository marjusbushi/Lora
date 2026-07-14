<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Supplier;
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

    private function reservation(): Reservation
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '1']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => '2026-08-01',
            'check_out_date' => '2026-08-03',
            'status' => 'confirmed',
        ]);
    }

    public function test_payment_rows_expose_structured_navigation_links(): void
    {
        $this->withoutVite();
        $admin = $this->admin();
        $reservation = $this->reservation();

        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 120,
            'method' => 'cash',
            'type' => 'payment',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('finance.payments', ['all_dates' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payments.data.0.account_id', fn ($id) => is_int($id))
                ->where('payments.data.0.related.reservation.id', $reservation->id)
                ->where('payments.data.0.related.reservation.href', route('reservations.show', $reservation))
                ->where('payments.data.0.created_by', $admin->name));
    }

    public function test_bill_payment_links_to_the_exact_bill_and_supplier(): void
    {
        $this->withoutVite();
        $admin = $this->admin();
        FinanceAccount::ensureDefaults();
        $supplier = Supplier::create(['name' => 'Eco Market', 'is_active' => true]);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'number' => 'INV-42',
            'category' => 'Ushqim & Pije',
            'issue_date' => today(),
            'currency' => 'EUR',
            'total' => 75,
            'status' => 'open',
        ]);
        $this->payment([
            'direction' => 'out',
            'amount' => 75,
            'bill_id' => $bill->id,
            'description' => 'Pagesë fature',
        ]);

        $this->actingAs($admin)->get(route('finance.payments', ['all_dates' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payments.data.0.related.bill.id', $bill->id)
                ->where('payments.data.0.related.bill.href', route('finance.bills', ['bill_id' => $bill->id]))
                ->where('payments.data.0.related.supplier.id', $supplier->id)
                ->where('payments.data.0.related.supplier.href', route('finance.suppliers', ['supplier_id' => $supplier->id])));

        $this->actingAs($admin)->get(route('finance.bills', ['bill_id' => $bill->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bills.total', 1)
                ->where('bills.data.0.id', $bill->id));

        $this->actingAs($admin)->get(route('finance.suppliers', ['supplier_id' => $supplier->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('focusSupplierId', $supplier->id));
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
