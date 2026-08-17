<?php

namespace Tests\Feature;

use App\Events\PosOrderChanged;
use App\Models\BeachZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * POS live (task #346): PosOrderChanged emetohet nga OBSERVER-i pas commit-it —
 * një pikë e vetme për çdo burim shkrimi (kasa, shërbimi në tavolinë, porosia
 * publike QR nga çadrat). Autorizimi cross-tenant i kanalit 'tenant.{id}.pos'
 * testohet te RealtimeChannelAuthTest (refuzim 403 me id të falsifikuar).
 */
class RealtimePosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        // Fake VETËM eventi i transmetimit — ngjarjet e modelit (observer-i)
        // duhet të ekzekutohen realisht, se ai është vetë subjekti i testit.
        Event::fake([PosOrderChanged::class]);
    }

    public function test_public_qr_order_from_sunbed_broadcasts(): void
    {
        $zone = BeachZone::create(['name' => 'Zona A', 'price_per_day' => 500]);
        $unit = $zone->units()->create(['number' => '7']);
        $beachBar = PosOutlet::create(['name' => 'Beach Bar']);
        Setting::set('beach.pos_outlet_id', $beachBar->id, 'number');
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $beer = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Bire', 'price' => 3.5, 'is_available' => true]);

        $this->post(route('website.beach.order.submit', $unit->qr_token), [
            'items' => [['menu_item_id' => $beer->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $order = PosOrder::query()->latest('id')->firstOrFail();
        Event::assertDispatched(PosOrderChanged::class, fn ($e) => $e->tenantId === $this->tenant->id
            && $e->orderId === $order->id);
    }

    public function test_staff_order_write_and_status_change_broadcast(): void
    {
        // Primitivi i përbashkët i çdo rruge stafi (kasa, rounds) — observer-i
        // s'varet nga rruga HTTP; rrjedhat e plota mbulohen nga suitat e tyre.
        $staff = User::query()->first() ?? User::factory()->create();

        $order = PosOrder::create([
            'status' => 'open',
            'total_amount' => 12.5,
            'created_by' => $staff->id,
        ]);

        Event::assertDispatched(PosOrderChanged::class, fn ($e) => $e->tenantId === $this->tenant->id
            && $e->orderId === $order->id);

        $base = Event::dispatched(PosOrderChanged::class)->count();
        $order->update(['status' => 'completed']);
        $this->assertSame($base + 1, Event::dispatched(PosOrderChanged::class)->count());
    }

    public function test_reads_never_broadcast(): void
    {
        PosOutlet::create(['name' => 'Restorant']);

        Event::assertNotDispatched(PosOrderChanged::class);
    }
}
