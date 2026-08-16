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
        $header = ['Book Number', 'Guest Name(s)', 'Status', 'Check-in', 'Check-out', 'Price', 'Commission Amount', 'Adults', 'Children', 'Rooms', 'Unit type', 'Remarks', 'Booker country', 'Phone number'];
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

    private function importBeds24Csv(array $rows): void
    {
        $header = ['Property', 'Room', 'Roomid', 'Unit', 'Ref', 'Status', 'First Night', 'Check Out', 'FirstNight', 'LastNight', 'CheckOut', 'First Name', 'Name', 'Price', 'Charges', 'Commission', 'Comments', 'Notes', 'Adult', 'Child', 'Email', 'Phone', 'Mobile', 'Country', 'Referer', 'ApiRef', 'ApiSource', 'Quantity'];
        $fh = fopen($this->csvPath, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn (string $col) => $row[$col] ?? '', $header));
        }
        fclose($fh);

        $this->artisan('booking:import', ['file' => $this->csvPath, '--tenant' => Tenant::query()->sole()->id])
            ->assertSuccessful();
    }

    public function test_beds24_export_imports_all_channels_from_one_file(): void
    {
        $this->makeType('Deluxe Double Studio', 3, 101);

        $this->importBeds24Csv([
            [
                // OTA row: channel from Referer, ref from ApiRef, past stay.
                'Room' => 'Deluxe Double Studio', 'Ref' => '88017042', 'Status' => 'Confirmed',
                'FirstNight' => now()->subDays(20)->toDateString(), 'CheckOut' => now()->subDays(17)->toDateString(),
                'First Name' => 'Ana', 'Name' => 'Berisha', 'Price' => '180.00', 'Commission' => '21.60',
                'Adult' => '2', 'Email' => 'ana.demo@guest.booking.com', 'Phone' => '+355691112222',
                'Country' => 'AL', 'Referer' => 'Booking.com', 'ApiRef' => '5438361798', 'ApiSource' => '19', 'Quantity' => '1',
            ],
            [
                // Manual row typed by staff: Referer is their email -> direct,
                // no ApiRef -> the Beds24 Ref becomes the reference.
                'Room' => 'Deluxe Double Studio', 'Ref' => '88020001', 'Status' => 'New',
                'FirstNight' => now()->addDays(5)->toDateString(), 'CheckOut' => now()->addDays(8)->toDateString(),
                'First Name' => 'Blerim', 'Name' => 'Hoxha', 'Price' => '240.00',
                'Adult' => '2', 'Referer' => 'jonabardhi13@gmail.com', 'ApiSource' => '0', 'Quantity' => '1',
            ],
            [
                // Expedia-group row with the Beds24 zero-price quirk: the
                // money sits in Charges.
                'Room' => 'Deluxe Double Studio', 'Ref' => '88020002', 'Status' => 'Confirmed',
                'FirstNight' => now()->addDays(12)->toDateString(), 'CheckOut' => now()->addDays(14)->toDateString(),
                'First Name' => 'Eva', 'Name' => 'Krasniqi', 'Price' => '0.00', 'Charges' => '250.00',
                'Adult' => '2', 'Referer' => 'ebookers', 'ApiRef' => 'EXP-771', 'ApiSource' => '14', 'Quantity' => '1',
            ],
        ]);

        $booking = Reservation::where('channel_ref', '5438361798')->sole();
        $this->assertSame('booking.com', $booking->channel);
        $this->assertSame('checked_out', $booking->status);
        $this->assertSame(180.0, (float) $booking->total_amount);
        $this->assertSame('ana.demo@guest.booking.com', $booking->guest->email);

        $direct = Reservation::where('channel_ref', '88020001')->sole();
        $this->assertSame('direct', $direct->channel);
        $this->assertSame('confirmed', $direct->status);

        $expedia = Reservation::where('channel_ref', 'EXP-771')->sole();
        $this->assertSame('expedia', $expedia->channel);
        $this->assertSame(250.0, (float) $expedia->total_amount);
    }

    public function test_history_rows_land_as_completed_stays_not_open_confirmations(): void
    {
        $this->makeType('Deluxe Double Room With Balcony', 2, 201);

        $this->importCsv([
            [
                'Book Number' => '4000000001', 'Guest Name(s)' => 'Old Guest',
                'Status' => 'ok',
                'Check-in' => now()->subMonths(6)->toDateString(),
                'Check-out' => now()->subMonths(6)->addDays(3)->toDateString(),
                'Price' => '300.00', 'Adults' => '2',
                'Unit type' => 'Deluxe Double Room with Balcony',
            ],
            [
                'Book Number' => '4000000002', 'Guest Name(s)' => 'Future Guest',
                'Status' => 'ok',
                'Check-in' => now()->addDays(10)->toDateString(),
                'Check-out' => now()->addDays(13)->toDateString(),
                'Price' => '300.00', 'Adults' => '2',
                'Unit type' => 'Deluxe Double Room with Balcony',
            ],
        ]);

        $this->assertSame('checked_out', Reservation::where('channel_ref', '4000000001')->sole()->status);
        $this->assertSame('confirmed', Reservation::where('channel_ref', '4000000002')->sole()->status);
    }

    public function test_two_rooms_of_the_same_unit_become_two_reservations_with_split_price(): void
    {
        // Booking.com's export lists a unit NAME once and carries the count in
        // the "Rooms" column — Nyemcsok's 2-room booking arrived as one name
        // with Rooms=2 and collapsed into a single €800 reservation.
        $this->makeType('Deluxe With Sea View', 3, 203);

        $this->importCsv([[
            'Book Number' => '5417794562', 'Guest Name(s)' => 'Pál Nyemcsok',
            'Status' => 'ok', 'Check-in' => '2026-08-18', 'Check-out' => '2026-08-22',
            'Price' => '800.00', 'Commission Amount' => '96.00', 'Adults' => '2', 'Rooms' => '2',
            'Unit type' => 'Deluxe Double Room with Balcony and Sea View',
        ]]);

        $reservations = Reservation::where('channel_ref', '5417794562')->get();
        $this->assertCount(2, $reservations);
        $this->assertSame([400.0, 400.0], $reservations->pluck('total_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(2, $reservations->pluck('room_id')->unique()->count());
        // The commission belongs to the booking once, not once per room.
        $this->assertSame(96.0, (float) $reservations->sum('commission_amount'));
    }

    public function test_mixed_units_with_a_higher_room_count_import_what_is_listed_and_flag_the_gap(): void
    {
        $this->makeType('Studio', 2, 301);
        $this->makeType('Triple Room', 2, 401);

        $header = ['Book Number', 'Guest Name(s)', 'Status', 'Check-in', 'Check-out', 'Price', 'Adults', 'Rooms', 'Unit type'];
        $fh = fopen($this->csvPath, 'w');
        fputcsv($fh, $header);
        fputcsv($fh, array_map(fn (string $col) => [
            'Book Number' => '7000000001', 'Guest Name(s)' => 'Test Guest',
            'Status' => 'ok', 'Check-in' => '2026-09-01', 'Check-out' => '2026-09-04',
            'Price' => '900.00', 'Adults' => '4', 'Rooms' => '3',
            'Unit type' => 'Studio, Triple Room',
        ][$col] ?? '', $header));
        fclose($fh);

        $this->artisan('booking:import', ['file' => $this->csvPath, '--tenant' => Tenant::query()->sole()->id])
            ->expectsOutputToContain('CSV kërkon 3 dhoma')
            ->assertSuccessful();

        // Ambiguous which unit repeats — only the two listed units are imported.
        $this->assertSame(2, Reservation::where('channel_ref', '7000000001')->count());
    }
}
