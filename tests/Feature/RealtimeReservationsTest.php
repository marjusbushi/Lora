<?php

namespace Tests\Feature;

use App\Events\ReservationChanged;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Rezervimet live (task #345): ReservationChanged emetohet nga OBSERVER-i pas
 * commit-it — një pikë e vetme që mbulon çdo burim shkrimi (web publik,
 * recepsion, importues Channex, komanda). Autorizimi cross-tenant i kanalit
 * 'tenant.{id}.reservations' testohet te RealtimeChannelAuthTest (refuzim 403).
 */
class RealtimeReservationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        // Fake VETËM eventi i transmetimit — ngjarjet e modelit (observer-i)
        // duhet të ekzekutohen realisht, se ai është vetë subjekti i testit.
        Event::fake([ReservationChanged::class]);
    }

    private function room(): Room
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);
    }

    public function test_booking_lifecycle_broadcasts_on_create_update_and_delete(): void
    {
        $room = $this->room();

        // 1) BURIMI WEB PUBLIK — rruga reale e mysafirit, fund-më-fund.
        $this->post(route('website.book.submit'), [
            'selections' => [['room_type_id' => $room->room_type_id, 'quantity' => 1]],
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'first_name' => 'Rina', 'last_name' => 'T', 'email' => 'rina@t.local', 'phone' => '+355 69 111',
            'adults' => 2, 'children' => 0, 'website' => '',
        ])->assertRedirect();

        $reservation = Reservation::latest('id')->firstOrFail();
        Event::assertDispatched(ReservationChanged::class, fn ($e) => $e->tenantId === $this->tenant->id
            && $e->reservationId === $reservation->id);

        // Rrjedha e web-it mund të bëjë më shumë se një shkrim (krijim +
        // plotësim pas-krijimi) — klienti i bashkon me debounce, ndaj këtu
        // matet DELTA për shkrim, jo një total i ngurtë.
        $base = Event::dispatched(ReservationChanged::class)->count();

        // 2) PËRDITËSIM (ndryshim statusi — anulim nga recepsioni).
        $reservation->update(['status' => 'cancelled']);
        $this->assertSame($base + 1, Event::dispatched(ReservationChanged::class)->count());

        // 3) FSHIRJE.
        $reservation->delete();
        $this->assertSame($base + 2, Event::dispatched(ReservationChanged::class)->count());
    }

    public function test_untouched_reads_never_broadcast(): void
    {
        $this->room();

        Event::assertNotDispatched(ReservationChanged::class);
    }
}
