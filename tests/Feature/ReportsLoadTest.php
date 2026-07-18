<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReportsLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_report_routes_render_with_data(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B', 'email' => 'a@b.local', 'phone' => '1', 'nationality' => 'AL']);
        $res = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
            'status' => 'checked_in', 'total_amount' => 160, 'adults' => 2, 'channel' => 'booking.com', 'commission_amount' => 24,
        ]);
        Payment::create(['reservation_id' => $res->id, 'amount' => 50, 'method' => 'cash', 'created_by' => $admin->id]);
        FolioItem::create(['reservation_id' => $res->id, 'description' => 'Birrë', 'amount' => 6, 'type' => 'bar', 'charge_date' => today()->toDateString()]);
        $cat = MenuCategory::create(['name' => 'Bar', 'sort_order' => 1]);
        $item = MenuItem::create(['menu_category_id' => $cat->id, 'name' => 'Birrë', 'price' => 3]);
        $order = PosOrder::create(['status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 6, 'created_by' => $admin->id]);
        PosOrderItem::create(['pos_order_id' => $order->id, 'menu_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 3, 'total_price' => 6]);

        $routes = [
            'reports.index' => 'Reports/Index',
            'reports.executive' => 'Reports/Executive',
            'reports.channels' => 'Reports/Channels',
            'reports.outstanding' => 'Reports/Outstanding',
            'reports.shifts' => 'Reports/Shifts',
            'reports.guests' => 'Reports/Guests',
            'reports.posSales' => 'Reports/PosSales',
            'reports.arrivalsManifest' => 'Reports/ArrivalsManifest',
            'reports.departuresManifest' => 'Reports/DeparturesManifest',
            'reports.pace' => 'Reports/Pace',
            'reports.cancellations' => 'Reports/Cancellations',
            'reports.payments' => 'Reports/Payments',
            'reports.vat' => 'Reports/Vat',
            'reports.performance' => 'Reports/Performance',
            'reports.repeatGuests' => 'Reports/RepeatGuests',
            'reports.nationality' => 'Reports/Nationality',
            'reports.bookingBehavior' => 'Reports/BookingBehavior',
            'reports.posHourly' => 'Reports/PosHourly',
            'reports.posPaymentMix' => 'Reports/PosPaymentMix',
            'reports.posVoids' => 'Reports/PosVoids',
            'reports.roomStatus' => 'Reports/RoomStatus',
            'reports.housekeepingReport' => 'Reports/Housekeeping',
            'reports.inHouse' => 'Reports/InHouse',
            'reports.discounts' => 'Reports/Discounts',
        ];
        foreach ($routes as $name => $component) {
            $this->actingAs($admin)->get(route($name))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $p) => $p->component($component));
        }

        $this->actingAs($admin)->from(route('reports.channels'))
            ->get(route('reports.channels', ['from' => '2026-07-31', 'to' => '2026-07-01']))
            ->assertRedirect(route('reports.channels'))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)->from(route('reports.bookingBehavior'))
            ->get(route('reports.bookingBehavior', ['from' => '2026-07-31', 'to' => '2026-07-01']))
            ->assertRedirect(route('reports.bookingBehavior'))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)
            ->get(route('reports.bookingBehavior', ['to' => today()->toDateString()]))
            ->assertOk();

        $this->actingAs($admin)->from(route('reports.cancellations'))
            ->get(route('reports.cancellations', ['from' => '2026-07-31', 'to' => '2026-07-01']))
            ->assertRedirect(route('reports.cancellations'))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)->from(route('reports.cancellations'))
            ->get(route('reports.cancellations', ['from' => '2025-01-01', 'to' => '2026-07-31']))
            ->assertRedirect(route('reports.cancellations'))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)->from(route('reports.pace'))
            ->get(route('reports.pace', ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]))
            ->assertRedirect(route('reports.pace'))
            ->assertSessionHasErrors('from');

        $this->actingAs($admin)->from(route('reports.pace'))
            ->get(route('reports.pace', ['from' => today()->toDateString(), 'to' => today()->addDays(365)->toDateString()]))
            ->assertRedirect(route('reports.pace'))
            ->assertSessionHasErrors('to');
    }
}
