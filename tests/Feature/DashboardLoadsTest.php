<?php

namespace Tests\Feature;

use App\Models\ChannelMapping;
use App\Models\ChannelSyncLog;
use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardLoadsTest extends TestCase
{
    use RefreshDatabase;

    private int $guestSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-10 12:00:00');
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_dashboard_exposes_the_operational_cockpit_contract(): void
    {
        Tenant::query()->sole()->update(['currency' => 'ALL']);

        $admin = $this->user('admin');
        $type = $this->roomType();
        $room = $this->room($type, '101');
        $this->reservation($room, $admin, 'pending', '2026-07-09', '2026-07-11');

        $response = $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));

        $props = $this->props($response);

        foreach (['permissions', 'operational', 'otaHealth', 'roomFlow', 'actions', 'ownerPulse', 'forecast', 'currency'] as $key) {
            $this->assertArrayHasKey($key, $props, "Dashboard is missing the top-level [{$key}] prop.");
        }

        $this->assertSame('L', $props['currency']);
        $this->assertIsArray($props['permissions']);
        $this->assertNotEmpty($props['permissions']);
        foreach ($props['permissions'] as $permission => $allowed) {
            $this->assertIsBool($allowed, "Dashboard permission [{$permission}] must be a boolean.");
        }

        $this->assertArrayHasKey('occupancy_tonight', $props['operational']);
        $this->assertSame(
            ['pct', 'sellable', 'sold'],
            $this->sortedKeys($props['operational']['occupancy_tonight']),
        );
        foreach (['arrivals', 'departures'] as $movement) {
            $this->assertSame(
                ['completed', 'remaining', 'total'],
                $this->sortedKeys($props['operational'][$movement]),
            );
        }
        $this->assertSame(['open', 'rush'], $this->sortedKeys($props['operational']['housekeeping']));
        $this->assertSame(['amount', 'count'], $this->sortedKeys($props['operational']['due_today']));
        $this->assertArrayHasKey('in_house_reservations', $props['operational']);
        $this->assertSame(['count', 'total'], $this->sortedKeys($props['operational']['open_pos']));

        $this->assertSame(
            ['applied_until', 'label', 'last_error_at', 'last_sync_at', 'mapped_room_types', 'sell_until', 'status'],
            $this->sortedKeys($props['otaHealth']),
        );
        $this->assertContains($props['otaHealth']['status'], ['ok', 'attention', 'waiting', 'not_configured']);

        $this->assertIsArray($props['roomFlow']);
        $this->assertIsArray($props['actions']);
        foreach ($props['actions'] as $action) {
            foreach (['type', 'level', 'title', 'detail', 'href', 'cta'] as $field) {
                $this->assertArrayHasKey($field, $action, "Dashboard action is missing [{$field}].");
            }
        }

        $this->assertSame(
            ['card_today', 'cash_today', 'collected_month', 'collected_month_delta', 'collected_month_prev', 'collected_today', 'top_channel'],
            $this->sortedKeys($props['ownerPulse']),
        );

        $this->assertNotEmpty($props['forecast']);
        foreach ($props['forecast'] as $day) {
            $this->assertSame(['date', 'pct', 'rooms'], $this->sortedKeys($day));
        }
    }

    public function test_occupancy_tonight_comes_from_reservations_and_excludes_maintenance_supply(): void
    {
        $admin = $this->user('admin');
        $type = $this->roomType();

        $reservedAvailableRoom = $this->room($type, '201', 'available');
        $this->room($type, '202', 'occupied'); // A stale physical status is not a sold night.
        $maintenanceRoom = $this->room($type, '203', 'maintenance');

        $this->reservation($reservedAvailableRoom, $admin, 'confirmed', '2026-07-10', '2026-07-12');
        $this->reservation($maintenanceRoom, $admin, 'confirmed', '2026-07-10', '2026-07-12');

        $occupancy = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk())['operational']['occupancy_tonight'];

        $this->assertSame(2, $occupancy['sellable']);
        $this->assertSame(1, $occupancy['sold']);
        $this->assertSame(50, $occupancy['pct']);
    }

    public function test_due_today_only_contains_checked_in_stays_due_to_leave_today_or_overdue(): void
    {
        $admin = $this->user('admin');
        $type = $this->roomType();

        $dueToday = $this->reservation(
            $this->room($type, '301'),
            $admin,
            'checked_in',
            '2026-07-08',
            '2026-07-10',
            120,
        );
        Payment::create([
            'reservation_id' => $dueToday->id,
            'amount' => 20,
            'method' => 'cash',
            'created_by' => $admin->id,
            'is_voided' => false,
        ]);

        $this->reservation(
            $this->room($type, '302'),
            $admin,
            'checked_in',
            '2026-07-06',
            '2026-07-09',
            80,
        );
        $this->reservation(
            $this->room($type, '303'),
            $admin,
            'checked_in',
            '2026-07-09',
            '2026-07-11',
            200,
        );
        $this->reservation(
            $this->room($type, '304'),
            $admin,
            'confirmed',
            '2026-08-01',
            '2026-08-05',
            300,
        );
        $this->reservation(
            $this->room($type, '305'),
            $admin,
            'checked_out',
            '2026-07-01',
            '2026-07-03',
            500,
        );

        $due = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk())['operational']['due_today'];

        $this->assertSame(2, $due['count']);
        $this->assertEqualsWithDelta(180, $due['amount'], 0.001);
    }

    public function test_check_in_is_hidden_and_blocked_until_the_room_is_ready(): void
    {
        $admin = $this->user('admin');
        $type = $this->roomType();
        $room = $this->room($type, '350', 'cleaning');
        $reservation = $this->reservation($room, $admin, 'confirmed', '2026-07-10', '2026-07-12');
        $task = CleaningTask::create([
            'room_id' => $room->id,
            'type' => 'checkout_clean',
            'status' => 'pending',
            'priority' => 'urgent',
        ]);

        $blockedProps = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk());
        $blockedRoom = collect($blockedProps['roomFlow'])->firstWhere('room_id', $room->id);
        $this->assertFalse($blockedRoom['arrival']['ready_for_check_in']);

        $this->actingAs($admin)
            ->post(route('reservations.check-in', $reservation))
            ->assertSessionHasErrors('check_in');
        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame('cleaning', $room->fresh()->status);

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $room->update(['status' => 'available']);

        $readyProps = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk());
        $readyRoom = collect($readyProps['roomFlow'])->firstWhere('room_id', $room->id);
        $this->assertTrue($readyRoom['arrival']['ready_for_check_in']);

        $this->actingAs($admin)
            ->post(route('reservations.check-in', $reservation))
            ->assertSessionHasNoErrors();
        $this->assertSame('checked_in', $reservation->fresh()->status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_housekeeper_does_not_receive_owner_finances_or_guest_reservation_data_in_room_flow(): void
    {
        $admin = $this->user('admin');
        $housekeeper = $this->user('housekeeping');
        $type = $this->roomType();
        $room = $this->room($type, '401', 'cleaning');

        $guest = Guest::create([
            'first_name' => 'SensitiveFirst',
            'last_name' => 'SensitiveLast',
            'email' => 'private-guest@example.test',
            'phone' => '+355690001111',
        ]);
        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-07-10',
            'check_out_date' => '2026-07-12',
            'status' => 'confirmed',
            'total_amount' => 250,
            'adults' => 2,
            'channel' => 'booking.com',
        ]);
        CleaningTask::create([
            'room_id' => $room->id,
            'type' => 'checkout_clean',
            'status' => 'pending',
            'priority' => 'urgent',
        ]);

        $props = $this->props($this->actingAs($housekeeper)->get(route('dashboard'))->assertOk());

        $this->assertSame(1, $props['operational']['arrivals']['remaining']);
        $housekeepingRoom = collect($props['roomFlow'])->firstWhere('room_id', $room->id);
        $this->assertTrue($housekeepingRoom['cleaning']['rush']);
        $this->assertSame(0, $housekeepingRoom['priority']);

        $this->assertTrue(
            ! array_key_exists('ownerPulse', $props) || $props['ownerPulse'] === null,
            'Housekeeping must not receive ownerPulse financial data in the response payload.',
        );

        $keys = $this->nestedKeys($props['roomFlow']);
        foreach (['arrival', 'departure', 'reservation', 'reservation_id', 'guest', 'guest_id'] as $sensitiveKey) {
            $this->assertNotContains(
                $sensitiveKey,
                $keys,
                "Housekeeping roomFlow leaked the sensitive [{$sensitiveKey}] field.",
            );
        }

        $serializedRoomFlow = json_encode($props['roomFlow'], JSON_THROW_ON_ERROR);
        foreach (['SensitiveFirst', 'SensitiveLast', 'private-guest@example.test', '+355690001111'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serializedRoomFlow);
        }
    }

    public function test_no_show_action_counts_pending_and_confirmed_past_arrivals_not_already_marked(): void
    {
        $admin = $this->user('admin');
        $type = $this->roomType();

        $this->reservation($this->room($type, '501'), $admin, 'pending', '2026-07-08', '2026-07-11');
        $this->reservation($this->room($type, '502'), $admin, 'confirmed', '2026-07-09', '2026-07-12');

        $alreadyMarked = $this->reservation(
            $this->room($type, '503'),
            $admin,
            'confirmed',
            '2026-07-08',
            '2026-07-11',
        );
        $alreadyMarked->update(['no_show_at' => '2026-07-09 18:00:00', 'no_show_by' => $admin->id]);

        $this->reservation($this->room($type, '504'), $admin, 'checked_in', '2026-07-08', '2026-07-11');
        $this->reservation($this->room($type, '505'), $admin, 'pending', '2026-07-10', '2026-07-11');

        $actions = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk())['actions'];
        $noShow = collect($actions)->firstWhere('type', 'no_show');

        $this->assertNotNull($noShow, 'A no-show action should be present for past pending/confirmed arrivals.');
        $this->assertSame(2, $noShow['count']);
        $this->assertNotEmpty($noShow['title']);
        $this->assertNotEmpty($noShow['detail']);
        $this->assertStringContainsString('2', $noShow['title'].' '.$noShow['detail']);

        $reportProps = $this->props($this->actingAs($admin)->get($noShow['href'])->assertOk());
        $this->assertSame(2, $reportProps['summary']['no_show_count']);
    }

    public function test_collected_today_uses_settlement_or_legacy_completion_date_not_creation_or_room_charge(): void
    {
        $admin = $this->user('admin');

        $this->posOrder(
            $admin,
            70,
            'cash',
            '2026-07-09 14:00:00',
            '2026-07-10 09:00:00',
            '2026-07-10',
        );
        $this->posOrder(
            $admin,
            90,
            'cash',
            '2026-07-10 08:00:00',
            '2026-07-09 22:00:00',
            '2026-07-09',
        );
        $this->posOrder(
            $admin,
            30,
            'card',
            '2026-07-09 20:00:00',
            '2026-07-10 10:00:00',
            null,
        );
        $this->posOrder(
            $admin,
            120,
            'card',
            '2026-07-10 10:30:00',
            null,
            null,
            '2026-07-09 10:30:00',
        );
        $this->posOrder(
            $admin,
            40,
            'cash',
            '2026-07-09 08:00:00',
            null,
            null,
            '2026-07-10 11:00:00',
        );
        $this->posOrder(
            $admin,
            200,
            'room_charge',
            '2026-07-10 09:00:00',
            '2026-07-10 09:15:00',
            '2026-07-10',
        );

        $pulse = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk())['ownerPulse'];

        $this->assertEqualsWithDelta(140, $pulse['collected_today'], 0.001);
        $this->assertEqualsWithDelta(110, $pulse['cash_today'], 0.001);
        $this->assertEqualsWithDelta(30, $pulse['card_today'], 0.001);
    }

    public function test_completing_a_pos_order_records_its_settlement_date_for_dashboard_collections(): void
    {
        $admin = $this->user('admin');
        PosShift::create([
            'user_id' => $admin->id,
            'status' => 'open',
            'opening_float' => 0,
            'opened_at' => now()->subHour(),
        ]);
        $order = PosOrder::create([
            'status' => 'open',
            'total_amount' => 55,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertSame('2026-07-10', $order->business_date?->toDateString());

        $pulse = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk())['ownerPulse'];
        $this->assertEqualsWithDelta(55, $pulse['collected_today'], 0.001);
        $this->assertEqualsWithDelta(55, $pulse['cash_today'], 0.001);
    }

    public function test_channex_error_is_only_cleared_by_a_later_success_for_the_same_action(): void
    {
        config([
            'services.channex.api_key' => 'local-test-key',
            'services.channex.property_id' => 'local-property',
        ]);
        $admin = $this->user('admin');
        $standard = $this->roomType();
        $deluxe = RoomType::create([
            'name' => 'Deluxe',
            'base_price' => 150,
            'max_occupancy' => 3,
            'amenities' => [],
        ]);
        $this->room($standard, '601');
        $this->room($deluxe, '602');
        foreach ([$standard, $deluxe] as $type) {
            ChannelMapping::create([
                'channel' => 'channex',
                'room_type_id' => $type->id,
                'channex_property_id' => 'local-property',
                'channex_room_type_id' => "remote-room-{$type->id}",
                'channex_rate_plan_id' => "remote-rate-{$type->id}",
            ]);
        }

        ChannelSyncLog::create([
            'channel' => 'channex',
            'direction' => 'push',
            'action' => 'availability',
            'room_type_id' => $standard->id,
            'status' => 'error',
            'created_at' => '2026-07-10 10:00:00',
        ]);
        ChannelSyncLog::create([
            'channel' => 'channex',
            'direction' => 'push',
            'action' => 'rate',
            'room_type_id' => $standard->id,
            'status' => 'ok',
            'created_at' => '2026-07-10 11:00:00',
        ]);
        foreach (['availability', 'rate'] as $action) {
            ChannelSyncLog::create([
                'channel' => 'channex',
                'direction' => 'push',
                'action' => $action,
                'room_type_id' => $deluxe->id,
                'status' => 'ok',
                'created_at' => '2026-07-10 11:00:00',
            ]);
        }

        $attention = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk());
        $this->assertSame('attention', $attention['otaHealth']['status']);

        ChannelSyncLog::create([
            'channel' => 'channex',
            'direction' => 'push',
            'action' => 'availability',
            'room_type_id' => $standard->id,
            'status' => 'ok',
            'created_at' => '2026-07-10 11:30:00',
        ]);

        $healthy = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk());
        $this->assertSame('ok', $healthy['otaHealth']['status']);
    }

    public function test_corrupt_sell_window_marks_attention_without_crashing_the_dashboard(): void
    {
        config([
            'services.channex.api_key' => 'local-test-key',
            'services.channex.property_id' => 'local-property',
        ]);
        Setting::set('channex.sell_until_date', 'not-a-date');
        $admin = $this->user('admin');

        $props = $this->props($this->actingAs($admin)->get(route('dashboard'))->assertOk());

        $this->assertSame('attention', $props['otaHealth']['status']);
        $this->assertNull($props['otaHealth']['sell_until']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function roomType(): RoomType
    {
        return RoomType::create([
            'name' => 'Standard',
            'base_price' => 100,
            'max_occupancy' => 3,
            'amenities' => [],
        ]);
    }

    private function room(RoomType $roomType, string $number, string $status = 'available'): Room
    {
        return Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => $number,
            'floor' => 1,
            'status' => $status,
        ]);
    }

    private function reservation(
        Room $room,
        User $creator,
        string $status,
        string $checkIn,
        string $checkOut,
        float $total = 100,
    ): Reservation {
        $this->guestSequence++;
        $guest = Guest::create([
            'first_name' => 'Guest'.$this->guestSequence,
            'last_name' => 'Test',
            'email' => "guest{$this->guestSequence}@example.test",
            'phone' => '+355690000'.str_pad((string) $this->guestSequence, 3, '0', STR_PAD_LEFT),
        ]);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $creator->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'status' => $status,
            'total_amount' => $total,
            'adults' => 2,
            'children' => 0,
            'channel' => 'direct',
        ]);
    }

    private function posOrder(
        User $creator,
        float $total,
        string $method,
        string $createdAt,
        ?string $paidAt,
        ?string $businessDate,
        ?string $updatedAt = null,
    ): PosOrder {
        $order = PosOrder::create([
            'status' => 'completed',
            'payment_method' => $method,
            'total_amount' => $total,
            'created_by' => $creator->id,
            'paid_at' => $paidAt,
            'business_date' => $businessDate,
        ]);

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ])->saveQuietly();

        return $order;
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        $page = json_decode(
            json_encode($response->viewData('page'), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $page['props'];
    }

    /** @return list<string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function nestedKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...$this->nestedKeys($child));
        }

        return array_values(array_unique($keys));
    }
}
