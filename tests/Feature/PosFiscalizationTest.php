<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosShift;
use App\Models\Setting;
use App\Models\TenantIntegration;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosFiscalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        TenantIntegration::query()->create([
            'provider' => 'fature_al',
            'enabled' => true,
            'credentials' => ['api_token' => 'sandbox-pos-token'],
            'configuration' => [
                'environment' => 'sandbox',
                'last_test_status' => 'success',
                'last_tested_at' => now()->toIso8601String(),
                'account' => [
                    'company' => 'Test Hotel sh.p.k.',
                    'nipt' => 'L00000000A',
                    'branch' => 'Bar',
                ],
            ],
        ]);
        Setting::set('financial.tax_rate', 20, 'number');
        Setting::set('financial.fx_all_per_eur', 93.7837, 'number');
    }

    public function test_cash_pos_sale_is_fiscalized_automatically_after_completion(): void
    {
        [$order] = $this->openOrder();

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(function (Request $request) use ($order) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://demo.fature.al/api/v1/invoice/cash'
                && $request->hasHeader('Authorization', 'Bearer sandbox-pos-token')
                && $payload['internalId'] === 'LORA-T'.$order->tenant_id.'-POS-'.$order->id
                && $payload['payment_method'] === 'BANKNOTE'
                && (float) $payload['exchange_rate'] === 93.7837
                && $payload['lines'][0]['product_name'] === 'Espresso'
                && (int) $payload['lines'][0]['quantity'] === 2
                && (float) $payload['lines'][0]['total'] === 3.0;
        });

        $document = PosFiscalDocument::query()->sole();
        $this->assertSame(PosFiscalDocument::STATUS_FISCALIZED, $document->status);
        $this->assertSame('POS-TEST-1', $document->fiscal_number);
        $this->assertSame('IIC-POS-TEST', $document->iic);
        $this->assertSame('Espresso', $document->invoice_payload['lines'][0]['product_name']);
        $this->assertSame('completed', $order->fresh()->status);

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('pos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.fiscal_document.fiscal_number', 'POS-TEST-1')
                ->where('orders.data.0.fiscal_document.iic', 'IIC-POS-TEST')
                ->where('orders.data.0.items.0.menu_item.name', 'Espresso')
                ->where('receiptSettings.nipt', 'L00000000A'));
    }

    public function test_retry_reconciles_existing_pos_invoice_without_creating_a_duplicate(): void
    {
        [$order] = $this->openOrder();
        $order->update(['status' => 'completed', 'payment_method' => 'card', 'paid_at' => now()]);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::sequence()
                ->push(['message' => 'temporary'], 500),
            'https://demo.fature.al/api/v1/invoice/details/*' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('pos.fiscalize', $order))
            ->assertSessionHasErrors('fiscalization');
        $this->assertSame(PosFiscalDocument::STATUS_FAILED, PosFiscalDocument::query()->sole()->status);

        $this->actingAs($this->admin)
            ->post(route('pos.fiscalize', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PosFiscalDocument::query()->count());
        $this->assertSame(PosFiscalDocument::STATUS_FISCALIZED, PosFiscalDocument::query()->sole()->status);
        $this->assertSame(1, Http::recorded(fn (Request $request) => $request->url()
            === 'https://demo.fature.al/api/v1/invoice/cash')->count());
    }

    public function test_room_charge_is_not_fiscalized_in_pos_to_avoid_double_billing(): void
    {
        [$order] = $this->openOrder();
        Http::preventStrayRequests();

        // The controller requires a checked-in reservation for a real room
        // charge. At service level, the important invariant is that POS never
        // accepts room_charge as a fiscal payment method.
        $order->update(['status' => 'completed', 'payment_method' => 'room_charge', 'paid_at' => now()]);

        $this->actingAs($this->admin)
            ->post(route('pos.fiscalize', $order))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, PosFiscalDocument::query()->count());
    }

    /** @return array{0:PosOrder,1:MenuItem} */
    private function openOrder(): array
    {
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $menuItem = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Espresso',
            'price' => 1.5,
            'is_available' => true,
        ]);
        $shift = PosShift::create([
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_float' => 0,
            'opened_at' => now(),
        ]);
        $order = PosOrder::create([
            'status' => 'open',
            'total_amount' => 3,
            'created_by' => $this->admin->id,
            'pos_shift_id' => $shift->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
            'unit_price' => 1.5,
            'total_price' => 3,
        ]);

        return [$order, $menuItem];
    }

    /** @return array<string, mixed> */
    private function successResponse(): array
    {
        return [
            'status' => true,
            'data' => [
                'invoice' => [
                    'id' => 9010,
                    'number' => 'POS-TEST-1',
                    'iic' => 'IIC-POS-TEST',
                    'fic' => 'FIC-POS-TEST',
                    'tcrCode' => 'TCR-POS',
                    'businessCode' => 'BUSINESS-POS',
                    'operatorCode' => 'OPERATOR-POS',
                    'fiscalizedAt' => now()->toIso8601String(),
                    'verifyURL' => 'https://demo.fature.al/verify/pos-test',
                    'pdf' => 'https://demo.fature.al/invoice/pos-test.pdf',
                ],
            ],
        ];
    }
}
