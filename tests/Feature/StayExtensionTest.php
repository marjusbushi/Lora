<?php

namespace Tests\Feature;

use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\FiscalDocument;
use App\Models\Guest;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ChannelSync;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StayExtensionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-27 10:00:00', 'Europe/Tirane'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function stay(string $channel = 'direct'): Reservation
    {
        $sequence = Room::query()->count() + 1;
        $type = RoomType::create([
            'name' => "Deluxe Extension {$sequence}",
            'base_price' => 100,
            'max_occupancy' => 3,
            'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => (string) (300 + $sequence),
            'floor' => 3,
            'status' => 'occupied',
        ]);
        $guest = Guest::create(['first_name' => 'Elira', 'last_name' => 'Demo']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'created_via' => $channel === 'direct'
                ? Reservation::CREATED_VIA_STAFF
                : Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => '2026-07-25',
            'check_out_date' => '2026-07-29',
            'status' => 'checked_in',
            'total_amount' => 400,
            'adults' => 2,
            'channel' => $channel,
            'commission_amount' => $channel === 'direct' ? 0 : 40,
        ]);
    }

    public function test_extension_uses_live_rates_blocks_ota_inventory_and_preserves_ota_commission(): void
    {
        config(['services.channex.api_key' => 'test-key']);
        $reservation = $this->stay('booking.com');
        RateOverride::create([
            'date' => '2026-07-29',
            'room_type_id' => $reservation->room->room_type_id,
            'price' => 125,
            'created_by' => $this->admin->id,
        ]);

        $quote = $this->actingAs($this->admin)->getJson(route('reservations.stay-extension.quote', [
            'reservation' => $reservation,
            'new_check_out_date' => '2026-07-31',
        ]));
        $quote->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('additional_nights', 2)
            ->assertJsonPath('quoted_extension', 225);

        $before = app(ChannelSync::class)->availabilityByDate(
            $reservation->room->roomType,
            CarbonImmutable::parse('2026-07-30'),
            CarbonImmutable::parse('2026-07-30'),
        );
        $this->assertSame(1, $before['2026-07-30']);

        Queue::fake();
        $this->actingAs($this->admin)->post(route('reservations.stay-extension', $reservation), [
            'new_check_out_date' => '2026-07-31',
            'extension_amount' => 220,
            'reason' => 'Mysafiri kërkoi edhe dy net',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame('checked_in', $reservation->status);
        $this->assertSame('2026-07-31', $reservation->check_out_date->toDateString());
        $this->assertSame('620.00', $reservation->total_amount);
        $this->assertSame('40.00', $reservation->commission_amount);
        $this->assertSame('occupied', $reservation->room->fresh()->status);

        $after = app(ChannelSync::class)->availabilityByDate(
            $reservation->room->roomType,
            CarbonImmutable::parse('2026-07-30'),
            CarbonImmutable::parse('2026-07-30'),
        );
        $this->assertSame(0, $after['2026-07-30']);

        Queue::assertPushed(PushRoomTypeAri::class, fn (PushRoomTypeAri $job) => (
            $job->roomTypeId === $reservation->room->room_type_id
            && $job->to === '2026-07-30'
        ));

        $audit = AuditLog::query()
            ->where('subject_type', Reservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', 'reservation.stay_extended')
            ->firstOrFail();
        $this->assertSame('2026-07-29', $audit->properties['original_check_out']);
        $this->assertSame('2026-07-31', $audit->properties['new_check_out']);
        $this->assertSame(2, $audit->properties['additional_nights']);
        $this->assertEquals(225, $audit->properties['quoted_extension']);
        $this->assertEquals(220, $audit->properties['agreed_extension']);
        $this->assertTrue($audit->properties['ota_contract_unchanged']);
    }

    public function test_extension_is_rejected_when_any_extra_night_is_already_booked(): void
    {
        $reservation = $this->stay();
        $otherGuest = Guest::create(['first_name' => 'Tjetër', 'last_name' => 'Mysafir']);
        Reservation::create([
            'room_id' => $reservation->room_id,
            'guest_id' => $otherGuest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => '2026-07-30',
            'check_out_date' => '2026-08-01',
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 1,
            'channel' => 'direct',
        ]);

        $this->actingAs($this->admin)->getJson(route('reservations.stay-extension.quote', [
            'reservation' => $reservation,
            'new_check_out_date' => '2026-07-31',
        ]))->assertUnprocessable()->assertJsonValidationErrors('new_check_out_date');

        $this->actingAs($this->admin)->post(route('reservations.stay-extension', $reservation), [
            'new_check_out_date' => '2026-07-31',
            'extension_amount' => 200,
            'reason' => 'Kërkesë për zgjatje',
        ])->assertSessionHasErrors('new_check_out_date');

        $reservation->refresh();
        $this->assertSame('2026-07-29', $reservation->check_out_date->toDateString());
        $this->assertSame('400.00', $reservation->total_amount);
        $this->assertDatabaseMissing('audit_logs', [
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
            'action' => 'reservation.stay_extended',
        ]);
    }

    public function test_extension_allows_a_complimentary_night_but_requires_a_reason(): void
    {
        $reservation = $this->stay();

        $this->actingAs($this->admin)->post(route('reservations.stay-extension', $reservation), [
            'new_check_out_date' => '2026-07-30',
            'extension_amount' => 0,
            'reason' => 'ok',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->post(route('reservations.stay-extension', $reservation), [
            'new_check_out_date' => '2026-07-30',
            'extension_amount' => 0,
            'reason' => 'Natë falas për rikuperim shërbimi',
        ])->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('2026-07-30', $reservation->check_out_date->toDateString());
        $this->assertSame('400.00', $reservation->total_amount);
    }

    public function test_extension_is_blocked_for_wrong_status_early_departure_plan_or_fiscalized_invoice(): void
    {
        $wrongStatus = $this->stay();
        $wrongStatus->update(['status' => 'confirmed']);
        $payload = [
            'new_check_out_date' => '2026-07-30',
            'extension_amount' => 100,
            'reason' => 'Kërkesë për zgjatje',
        ];
        $this->actingAs($this->admin)
            ->post(route('reservations.stay-extension', $wrongStatus), $payload)
            ->assertSessionHasErrors('new_check_out_date');

        $planned = $this->stay();
        $planned->update([
            'original_check_out_date' => '2026-07-29',
            'early_departure_original_room_total' => 400,
            'check_out_date' => '2026-07-28',
            'early_departure_scheduled_at' => now(),
            'early_departure_policy' => 'waive',
            'early_departure_reason' => 'Plan paraprak',
        ]);
        $this->actingAs($this->admin)
            ->post(route('reservations.stay-extension', $planned), $payload)
            ->assertSessionHasErrors('new_check_out_date');

        $fiscalized = $this->stay();
        FiscalDocument::create([
            'reservation_id' => $fiscalized->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'RES-'.$fiscalized->id,
            'payment_method' => 'BANKNOTE',
            'currency' => 'EUR',
            'total' => 400,
            'vat_rate' => 6,
            'request_hash' => str_repeat('e', 64),
            'status' => FiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'FISC-STAY-EXTENSION',
            'fiscalized_at' => now(),
            'attempted_at' => now(),
        ]);
        $this->actingAs($this->admin)
            ->post(route('reservations.stay-extension', $fiscalized), $payload)
            ->assertSessionHasErrors('new_check_out_date');

        $this->assertSame('2026-07-29', $wrongStatus->fresh()->check_out_date->toDateString());
        $this->assertSame('2026-07-28', $planned->fresh()->check_out_date->toDateString());
        $this->assertSame('2026-07-29', $fiscalized->fresh()->check_out_date->toDateString());
    }
}
