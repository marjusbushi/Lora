<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Booking.com CSV import (booking:import). Covers the production misimport
 * found at Villa Mucho: Booking.com's "Deluxe Double Room with Balcony and
 * Sea View" unit must land on the hotel's "Deluxe With Sea View" type —
 * confirmed by the hotel's own Channex channel mapping — not on
 * "Deluxe Double Room With Balcony" as the old alias assumed.
 */
class BookingCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        User::factory()->create();
        $this->csvPath = tempnam(sys_get_temp_dir(), 'booking-csv-');
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);
        parent::tearDown();
    }

    private function makeType(string $name, int $rooms, int $startNumber): RoomType
    {
        $type = RoomType::create(['name' => $name, 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        for ($i = 0; $i < $rooms; $i++) {
            Room::create(['room_type_id' => $type->id, 'room_number' => (string) ($startNumber + $i), 'floor' => 1, 'status' => 'available']);
        }

        return $type;
    }

    private function importCsv(array $rows): void
    {
        $header = ['Book Number', 'Guest Name(s)', 'Status', 'Check-in', 'Check-out', 'Price', 'Commission Amount', 'Adults', 'Children', 'Unit type', 'Remarks', 'Booker country', 'Phone number'];
        $fh = fopen($this->csvPath, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn (string $col) => $row[$col] ?? '', $header));
        }
        fclose($fh);

        $this->artisan('booking:import', ['file' => $this->csvPath, '--tenant' => Tenant::query()->sole()->id])
            ->assertSuccessful();
    }

    public function test_balcony_and_sea_view_unit_lands_on_the_sea_view_type(): void
    {
        $balcony = $this->makeType('Deluxe Double Room With Balcony', 1, 201);
        $seaView = $this->makeType('Deluxe With Sea View', 2, 203);

        $this->importCsv([[
            'Book Number' => '5438361798', 'Guest Name(s)' => 'Christian Iannello',
            'Status' => 'ok', 'Check-in' => '2026-08-12', 'Check-out' => '2026-08-18',
            'Price' => '540.00', 'Commission Amount' => '64.80', 'Adults' => '2',
            'Unit type' => 'Deluxe Double Room with Balcony and Sea View',
        ]]);

        $reservation = Reservation::where('channel_ref', '5438361798')->sole();
        $this->assertSame($seaView->id, $reservation->room->room_type_id);
        $this->assertNotSame($balcony->id, $reservation->room->room_type_id);
    }

    public function test_plain_balcony_unit_still_lands_on_the_balcony_type(): void
    {
        $balcony = $this->makeType('Deluxe Double Room With Balcony', 1, 201);
        $this->makeType('Deluxe With Sea View', 2, 203);

        $this->importCsv([[
            'Book Number' => '6008957206', 'Guest Name(s)' => 'Test Guest',
            'Status' => 'ok', 'Check-in' => '2026-08-27', 'Check-out' => '2026-08-31',
            'Price' => '270.00', 'Commission Amount' => '32.40', 'Adults' => '2',
            'Unit type' => 'Deluxe Double Room with Balcony',
        ]]);

        $reservation = Reservation::where('channel_ref', '6008957206')->sole();
        $this->assertSame($balcony->id, $reservation->room->room_type_id);
    }
}
