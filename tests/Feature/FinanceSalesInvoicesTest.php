<?php

namespace Tests\Feature;

use App\Models\FiscalDocument;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FinanceSalesInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::query()->sole();
        $this->admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $this->admin->assignRole('admin');
    }

    public function test_register_combines_hotel_and_direct_pos_invoices_with_real_line_details(): void
    {
        $reservation = $this->hotelStay();
        $this->fiscalizeHotelRecord($reservation);
        $order = $this->paidPosOrder();

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('finance.invoices'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Finance/Invoices')
                ->where('summary.total_count', 2)
                ->where('summary.fiscalized_count', 1)
                ->where('summary.not_fiscalized_count', 1)
                ->where('summary.hotel_count', 1)
                ->where('summary.pos_count', 1)
                ->where('summary.total_value', 108)
                ->where('summary.status_counts.all', 2)
                ->where('summary.status_counts.fiscalized', 1)
                ->where('summary.status_counts.not_fiscalized', 1)
                ->where('invoices.total', 2)
                ->where('invoices.data', function ($rows) use ($reservation, $order) {
                    $rows = collect($rows)->keyBy('key');

                    return $rows->has('hotel:'.$reservation->id)
                        && $rows->has('pos:'.$order->id)
                        && $rows['hotel:'.$reservation->id]['number'] === 'TEST-HOTEL-1'
                        && $rows['hotel:'.$reservation->id]['status'] === 'fiscalized'
                        && $rows['hotel:'.$reservation->id]['lines'][0]['vat_rate'] === 6
                        && $rows['hotel:'.$reservation->id]['lines'][1]['vat_rate'] === 20
                        && $rows['pos:'.$order->id]['status'] === 'pending'
                        && $rows['pos:'.$order->id]['lines'][0]['name'] === 'Espresso'
                        && $rows['pos:'.$order->id]['detail_href'] === route('pos.index', ['order_id' => $order->id]);
                }));
    }

    public function test_register_filters_source_and_fiscal_state(): void
    {
        $reservation = $this->hotelStay();
        $this->fiscalizeHotelRecord($reservation);
        $order = $this->paidPosOrder();

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('finance.invoices', ['source' => 'pos']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.total', 1)
                ->where('invoices.data.0.key', 'pos:'.$order->id));

        $this->actingAs($this->admin)->get(route('finance.invoices', ['status' => 'fiscalized']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.total', 1)
                ->where('summary.total_count', 1)
                ->where('summary.total_value', 105)
                ->where('summary.status_counts.all', 2)
                ->where('invoices.data.0.key', 'hotel:'.$reservation->id));

        $this->actingAs($this->admin)->get(route('finance.invoices', ['status' => 'not_fiscalized']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.total', 1)
                ->where('summary.total_count', 1)
                ->where('summary.total_value', 3)
                ->where('invoices.data.0.key', 'pos:'.$order->id));
    }

    public function test_register_never_leaks_another_tenants_invoices(): void
    {
        $reservation = $this->hotelStay();
        $otherTenant = Tenant::factory()->create(['name' => 'Other Hotel']);

        app(TenantContext::class)->run($otherTenant, function () {
            $otherUser = User::factory()->create(['current_tenant_id' => app(TenantContext::class)->id()]);
            $this->hotelStay($otherUser, 'Other Guest');
            $this->paidPosOrder($otherUser, 'Other Product');
        });

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('finance.invoices'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_count', 1)
                ->where('invoices.total', 1)
                ->where('invoices.data.0.key', 'hotel:'.$reservation->id)
                ->where('invoices.data.0.client', 'Elira Demo'));
    }

    public function test_date_filter_uses_the_same_fiscalized_date_shown_in_the_register(): void
    {
        $order = $this->paidPosOrder();
        $order->forceFill(['paid_at' => '2026-07-10 10:00:00'])->save();
        PosFiscalDocument::create([
            'pos_order_id' => $order->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'LORA-T'.$order->tenant_id.'-POS-'.$order->id,
            'payment_method' => 'BANKNOTE',
            'currency' => 'EUR',
            'total' => 3,
            'vat_rate' => 20,
            'invoice_payload' => [
                'lines' => [
                    ['product_name' => 'Espresso', 'quantity' => 2, 'unit' => 'copë', 'price' => 1.5, 'total' => 3, 'vat' => 20],
                ],
            ],
            'request_hash' => str_repeat('b', 64),
            'status' => PosFiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'TEST-POS-DATE',
            'fiscalized_at' => '2026-07-15 12:30:00',
        ]);

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('finance.invoices', [
            'date_from' => '2026-07-15',
            'date_to' => '2026-07-15',
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoices.total', 1)
            ->where('invoices.data.0.key', 'pos:'.$order->id)
            ->where('invoices.data.0.issued_at', fn ($value) => str_starts_with($value, '2026-07-15')));

        $this->actingAs($this->admin)->get(route('finance.invoices', [
            'date_from' => '2026-07-10',
            'date_to' => '2026-07-10',
        ]))->assertInertia(fn (AssertableInertia $page) => $page->where('invoices.total', 0));
    }

    private function hotelStay(?User $user = null, string $guestName = 'Elira Demo'): Reservation
    {
        $user ??= $this->admin;
        [$firstName, $lastName] = array_pad(explode(' ', $guestName, 2), 2, 'Guest');
        $roomType = RoomType::create([
            'name' => 'Deluxe',
            'base_price' => 100,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'floor' => 1,
            'status' => 'cleaning',
        ]);
        $guest = Guest::create(['first_name' => $firstName, 'last_name' => $lastName]);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => now()->subDays(2)->toDateString(),
            'check_out_date' => now()->subDay()->toDateString(),
            'status' => 'checked_out',
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
        FolioItem::create([
            'reservation_id' => $reservation->id,
            'description' => 'Minibar ujë',
            'amount' => 10,
            'type' => 'minibar',
            'vat_rate' => 20,
            'charge_date' => today(),
        ]);
        FolioItem::create([
            'reservation_id' => $reservation->id,
            'description' => 'Zbritje',
            'amount' => 5,
            'type' => 'discount',
            'charge_date' => today(),
        ]);
        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 105,
            'method' => 'cash',
            'created_by' => $user->id,
        ]);

        return $reservation;
    }

    private function fiscalizeHotelRecord(Reservation $reservation): void
    {
        FiscalDocument::create([
            'reservation_id' => $reservation->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'LORA-T'.$reservation->tenant_id.'-RES-'.$reservation->id,
            'payment_method' => 'BANKNOTE',
            'currency' => 'EUR',
            'total' => 105,
            'vat_rate' => 6,
            'invoice_payload' => [
                'lines' => [
                    ['product_name' => 'Akomodim · Dhoma 101', 'quantity' => 1, 'unit' => 'natë', 'price' => 100, 'total' => 100, 'vat' => 6],
                    ['product_name' => 'Minibar ujë', 'quantity' => 1, 'unit' => 'copë', 'price' => 10, 'total' => 10, 'vat' => 20],
                ],
                'invoice_discount_value' => 5,
            ],
            'request_hash' => str_repeat('a', 64),
            'status' => FiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'TEST-HOTEL-1',
            'iic' => 'IIC-HOTEL-1',
            'fiscalized_at' => now(),
            'verify_url' => 'https://demo.fature.al/verify/test-hotel-1',
        ]);
    }

    private function paidPosOrder(?User $user = null, string $productName = 'Espresso'): PosOrder
    {
        $user ??= $this->admin;
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => $productName,
            'price' => 1.5,
            'is_available' => true,
        ]);
        $order = PosOrder::create([
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 3,
            'created_by' => $user->id,
            'paid_at' => now(),
            'business_date' => today(),
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'menu_item_id' => $item->id,
            'quantity' => 2,
            'unit_price' => 1.5,
            'total_price' => 3,
        ]);

        return $order;
    }
}
