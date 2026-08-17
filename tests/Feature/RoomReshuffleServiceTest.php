<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\RoomReshuffleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoomReshuffleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $type;

    private User $user;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake(); // reservation saves fire the availability-push observer
        $this->type = RoomType::create(['name' => 'Studio', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $this->user = User::factory()->create();
        $this->guest = Guest::create(['first_name' => 'Mysafir', 'last_name' => 'Test']);
    }

    private function room(string $number, string $status = 'available'): Room
    {
        return Room::create(['room_type_id' => $this->type->id, 'room_number' => $number, 'floor' => 1, 'status' => $status]);
    }

    private function stay(Room $room, int $inDays, int $outDays, array $attrs = []): Reservation
    {
        return Reservation::create(array_merge([
            'room_id' => $room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->user->id,
            'check_in_date' => today()->addDays($inDays)->toDateString(),
            'check_out_date' => today()->addDays($outDays)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 2,
        ], $attrs));
    }

    private function plan(int $inDays, int $outDays, array $excludeRoomIds = []): ?array
    {
        return app(RoomReshuffleService::class)->planForIncoming(
            $this->type->id,
            today()->addDays($inDays)->toDateString(),
            today()->addDays($outDays)->toDateString(),
            $excludeRoomIds,
        );
    }

    /**
     * Apply the plan in memory and check it actually works: the freed room has
     * no active stay overlapping the incoming span, and no move lands a stay on
     * a room where it overlaps another (post-move) stay.
     */
    private function assertPlanIsSound(array $plan, int $inDays, int $outDays): void
    {
        $roomOf = Reservation::whereNotIn('status', ['cancelled', 'checked_out'])->pluck('room_id', 'id')->all();
        foreach ($plan['moves'] as $move) {
            $this->assertSame($roomOf[$move['reservation_id']], $move['from_room_id'], 'move lists the wrong source room');
            $roomOf[$move['reservation_id']] = $move['to_room_id'];
        }

        $in = today()->addDays($inDays)->toDateString();
        $out = today()->addDays($outDays)->toDateString();
        $stays = Reservation::whereNotIn('status', ['cancelled', 'checked_out'])->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id,
                'room_id' => $roomOf[$r->id],
                'in' => $r->check_in_date->toDateString(),
                'out' => $r->check_out_date->toDateString(),
            ])
            ->push(['id' => 0, 'room_id' => $plan['room_id'], 'in' => $in, 'out' => $out]);

        $movedIds = array_column($plan['moves'], 'reservation_id');
        foreach ($stays as $a) {
            foreach ($stays as $b) {
                if ($a['id'] >= $b['id'] || $a['room_id'] !== $b['room_id']) {
                    continue;
                }
                // A pre-existing collision the plan did not touch is out of scope.
                if ($a['id'] !== 0 && $b['id'] !== 0 && ! in_array($a['id'], $movedIds, true) && ! in_array($b['id'], $movedIds, true)) {
                    continue;
                }
                $this->assertFalse(
                    $a['in'] < $b['out'] && $a['out'] > $b['in'],
                    "plan leaves stays #{$a['id']} and #{$b['id']} overlapping on room {$a['room_id']}",
                );
            }
        }
    }

    public function test_fragmentation_is_solved_with_a_single_move(): void
    {
        // The Saturn pattern: two staggered stays block both rooms, yet moving
        // ONE of them empties a room for the full incoming span.
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $early = $this->stay($roomA, 1, 3);
        $this->stay($roomB, 5, 8);

        $plan = $this->plan(1, 8);

        $this->assertNotNull($plan);
        $this->assertSame($roomA->id, $plan['room_id']);
        $this->assertSame(
            [['reservation_id' => $early->id, 'from_room_id' => $roomA->id, 'to_room_id' => $roomB->id]],
            $plan['moves'],
        );
        $this->assertPlanIsSound($plan, 1, 8);
    }

    public function test_a_room_already_free_for_the_span_needs_no_moves(): void
    {
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 1, 4);

        $plan = $this->plan(2, 5);

        $this->assertNotNull($plan);
        $this->assertSame($roomB->id, $plan['room_id']);
        $this->assertSame([], $plan['moves']);
    }

    public function test_checkout_day_is_not_a_conflict(): void
    {
        // Back-to-back: the existing stay checks out the day the new one arrives.
        $roomA = $this->room('A1');
        $this->stay($roomA, 1, 3);

        $plan = $this->plan(3, 5);

        $this->assertNotNull($plan);
        $this->assertSame($roomA->id, $plan['room_id']);
        $this->assertSame([], $plan['moves']);
    }

    public function test_a_checked_in_guest_is_never_moved(): void
    {
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 0, 9, ['status' => 'checked_in']);
        $this->stay($roomB, 2, 4); // movable, but the only escape room is occupied by the guest

        $this->assertNull($this->plan(1, 8));
    }

    public function test_a_stay_past_its_check_in_date_is_never_moved(): void
    {
        // Still 'confirmed' but the check-in date has passed — the guest may
        // physically be in the room; treat exactly like checked-in.
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, -1, 9);
        $this->stay($roomB, 2, 4);

        $this->assertNull($this->plan(1, 8));
    }

    public function test_a_no_show_stay_is_never_moved(): void
    {
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 1, 9, ['no_show_at' => now()]);
        $this->stay($roomB, 2, 4);

        $this->assertNull($this->plan(1, 8));
    }

    public function test_maintenance_rooms_are_never_used(): void
    {
        $roomA = $this->room('A1');
        $this->room('M1', 'maintenance'); // free the whole time — but unusable
        $this->stay($roomA, 2, 4);

        $this->assertNull($this->plan(1, 8));
    }

    public function test_excluded_rooms_are_neither_freed_nor_move_targets(): void
    {
        $roomA = $this->room('A1');
        $roomB = $this->room('B1'); // free — but excluded (e.g. a sibling row's room)
        $this->stay($roomA, 2, 4);

        $this->assertNull($this->plan(1, 8, [$roomB->id]));
    }

    public function test_a_span_that_already_started_is_never_planned(): void
    {
        // Even with a completely free room, a span starting in the past gets no
        // plan — its past nights collide with stays the planner does not load.
        $this->room('A1');
        $this->room('B1');

        $this->assertNull($this->plan(-1, 5));
    }

    public function test_a_truly_oversold_type_returns_null(): void
    {
        $roomA = $this->room('A1');
        $this->stay($roomA, 2, 4);

        $this->assertNull($this->plan(1, 8));
    }

    public function test_a_multi_move_chain_frees_a_room(): void
    {
        // No single relocation works here: emptying A takes BOTH of its stays,
        // each into a different room's gap (a1 before b, a2 after c).
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $roomC = $this->room('C1');
        $a1 = $this->stay($roomA, 1, 2);
        $a2 = $this->stay($roomA, 4, 6);
        $this->stay($roomB, 2, 6);
        $this->stay($roomC, 1, 4);

        $plan = $this->plan(1, 6);

        $this->assertNotNull($plan);
        $this->assertSame($roomA->id, $plan['room_id']);
        $this->assertSame([
            ['reservation_id' => $a1->id, 'from_room_id' => $roomA->id, 'to_room_id' => $roomB->id],
            ['reservation_id' => $a2->id, 'from_room_id' => $roomA->id, 'to_room_id' => $roomC->id],
        ], $plan['moves']);
        $this->assertPlanIsSound($plan, 1, 6);
    }

    public function test_an_existing_parked_collision_does_not_block_planning(): void
    {
        // R1 already carries an MBI-BOOKIM collision (two overlapping stays).
        // The planner must still place the incoming stay on the free room and
        // leave the pre-existing collision untouched.
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 1, 4);
        $this->stay($roomA, 2, 5); // parked collision
        $this->stay($roomB, 10, 12);

        $plan = $this->plan(1, 8);

        $this->assertNotNull($plan);
        $this->assertSame($roomB->id, $plan['room_id']);
        $this->assertSame([], $plan['moves']);
        $this->assertPlanIsSound($plan, 1, 8);
    }

    public function test_excluded_reservations_do_not_occupy_the_universe(): void
    {
        // The stay being re-homed must not block itself: excluded, the only
        // room frees with zero moves; included, the type is simply oversold.
        $roomA = $this->room('A1');
        $stay = $this->stay($roomA, 2, 4);
        $service = app(RoomReshuffleService::class);
        $in = today()->addDays(1)->toDateString();
        $out = today()->addDays(8)->toDateString();

        $this->assertNull($service->planForIncoming($this->type->id, $in, $out));
        $this->assertSame(
            ['room_id' => $roomA->id, 'moves' => []],
            $service->planForIncoming($this->type->id, $in, $out, [], [$stay->id]),
        );
    }

    public function test_best_free_room_prefers_the_tightest_gap(): void
    {
        // Between a completely empty room and one whose stay ends exactly when
        // the span starts, the snug room wins — the empty room's long free run
        // is preserved for a future long booking.
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomB, 1, 3);

        $best = app(RoomReshuffleService::class)->bestFreeRoom(
            $this->type->id,
            today()->addDays(3)->toDateString(),
            today()->addDays(5)->toDateString(),
        );

        $this->assertSame($roomB->id, $best);
    }

    public function test_best_free_room_returns_null_when_nothing_fits_or_span_started(): void
    {
        $roomA = $this->room('A1');
        $this->stay($roomA, 2, 4);
        $service = app(RoomReshuffleService::class);

        // The only room overlaps the span — a best fit would need moves.
        $this->assertNull($service->bestFreeRoom(
            $this->type->id, today()->addDays(1)->toDateString(), today()->addDays(8)->toDateString(),
        ));
        // A started span is refused even though a slot exists (days 5-6 are free).
        $this->assertNull($service->bestFreeRoom(
            $this->type->id, today()->subDay()->toDateString(), today()->addDays(1)->toDateString(),
        ));
    }

    public function test_split_plan_covers_the_span_with_two_segments_when_checked_in_guests_anchor(): void
    {
        // Nightly inventory covers the whole span, but the two checked-in guests
        // anchor a pattern no reshuffle can untangle — the classic split case.
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 0, 4, ['status' => 'checked_in']);
        $this->stay($roomB, 4, 9, ['status' => 'checked_in']);
        $service = app(RoomReshuffleService::class);
        $in = today()->addDays(1)->toDateString();
        $out = today()->addDays(8)->toDateString();

        $this->assertNull($service->planForIncoming($this->type->id, $in, $out)); // no single-room answer
        $this->assertSame([
            'segments' => [
                ['room_id' => $roomB->id, 'check_in' => $in, 'check_out' => today()->addDays(4)->toDateString()],
                ['room_id' => $roomA->id, 'check_in' => today()->addDays(4)->toDateString(), 'check_out' => $out],
            ],
        ], $service->splitPlanForIncoming($this->type->id, $in, $out));
    }

    public function test_split_plan_is_null_when_a_night_is_uncovered(): void
    {
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        $this->stay($roomA, 0, 9, ['status' => 'checked_in']);
        $this->stay($roomB, 2, 5, ['status' => 'checked_in']); // night d+2 has NO free room

        $this->assertNull(app(RoomReshuffleService::class)->splitPlanForIncoming(
            $this->type->id, today()->addDays(1)->toDateString(), today()->addDays(8)->toDateString(),
        ));
    }

    public function test_split_plan_refuses_more_than_three_segments(): void
    {
        // Alternating pinned checkerboard would need 4+ hops — churn no guest
        // should be asked for.
        $roomA = $this->room('A1');
        $roomB = $this->room('B1');
        foreach ([[2, 3], [4, 5]] as [$in, $out]) {
            $this->stay($roomA, $in, $out, ['status' => 'checked_in']);
        }
        foreach ([[1, 2], [3, 4], [5, 6]] as [$in, $out]) {
            $this->stay($roomB, $in, $out, ['status' => 'checked_in']);
        }

        $this->assertNull(app(RoomReshuffleService::class)->splitPlanForIncoming(
            $this->type->id, today()->addDays(1)->toDateString(), today()->addDays(7)->toDateString(),
        ));
    }

    public function test_split_plan_never_uses_other_types_or_maintenance_rooms(): void
    {
        $roomA = $this->room('A1'); // the type's only serviceable room
        $this->room('M1', 'maintenance'); // free the whole time — unusable
        $otherType = RoomType::create(['name' => 'Apartment', 'base_price' => 200, 'max_occupancy' => 5, 'amenities' => []]);
        Room::create(['room_type_id' => $otherType->id, 'room_number' => 'X1', 'floor' => 1, 'status' => 'available']);
        $this->stay($roomA, 3, 5, ['status' => 'checked_in']);

        $this->assertNull(app(RoomReshuffleService::class)->splitPlanForIncoming(
            $this->type->id, today()->addDays(1)->toDateString(), today()->addDays(8)->toDateString(),
        ));
    }

    public function test_split_plan_is_null_when_one_room_covers_everything(): void
    {
        // A fully-free room is the primary paths' job, never a split.
        $this->room('A1');

        $this->assertNull(app(RoomReshuffleService::class)->splitPlanForIncoming(
            $this->type->id, today()->addDays(1)->toDateString(), today()->addDays(8)->toDateString(),
        ));
    }

    public function test_cancelled_and_checked_out_stays_do_not_occupy_rooms(): void
    {
        $roomA = $this->room('A1');
        $this->stay($roomA, 1, 4, ['status' => 'cancelled']);
        $this->stay($roomA, 2, 6, ['status' => 'checked_out']);

        $plan = $this->plan(1, 8);

        $this->assertNotNull($plan);
        $this->assertSame($roomA->id, $plan['room_id']);
        $this->assertSame([], $plan['moves']);
    }
}
