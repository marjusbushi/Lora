<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Models\WebsiteSearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #191 (Copa 1c): public availability searches are logged (dates,
 * results, denial flag) with zero PII, and a logging failure never breaks
 * the guest's availability check.
 */
class WebsiteSearchLogTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
    {
        $type = RoomType::create(['name' => 'Twin', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
    }

    public function test_search_is_logged_with_results_count(): void
    {
        $this->room();
        $in = now()->addDays(3)->toDateString();
        $out = now()->addDays(5)->toDateString();

        $this->post(route('website.book.check'), ['check_in' => $in, 'check_out' => $out])
            ->assertOk();

        $log = WebsiteSearchLog::first();
        $this->assertNotNull($log);
        $this->assertSame($in, $log->check_in->toDateString());
        $this->assertSame($out, $log->check_out->toDateString());
        $this->assertSame(1, $log->results_count);
        $this->assertFalse($log->denied);
        $this->assertSame('book', $log->source);
    }

    public function test_fully_booked_search_is_flagged_denied(): void
    {
        $room = $this->room();
        $in = now()->addDays(3)->toDateString();
        $out = now()->addDays(5)->toDateString();

        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'B', 'last_name' => 'Z'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'status' => 'confirmed',
            'total_amount' => 160,
            'adults' => 2,
            'channel' => 'direct',
        ]);

        $this->post(route('website.book.check'), ['check_in' => $in, 'check_out' => $out])
            ->assertOk();

        $log = WebsiteSearchLog::first();
        $this->assertTrue($log->denied, 'zero available rooms → denied search (a lost sale we can now see)');
        $this->assertSame(0, $log->results_count);
    }

    public function test_logging_failure_does_not_break_the_availability_response(): void
    {
        $this->room();
        Schema::drop('website_search_logs'); // simulate a broken log target

        $this->post(route('website.book.check'), [
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
        ])->assertOk()->assertJsonStructure(['room_types', 'nights']);
    }

    public function test_table_holds_no_pii(): void
    {
        foreach (['name', 'first_name', 'email', 'phone', 'ip', 'ip_address', 'user_agent'] as $col) {
            $this->assertFalse(
                Schema::hasColumn('website_search_logs', $col),
                "website_search_logs must not carry PII column '{$col}'",
            );
        }
    }
}
