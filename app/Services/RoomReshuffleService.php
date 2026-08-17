<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;

/**
 * Same-type room reshuffle planner. When no single room of a type is free for
 * an incoming stay, decides whether re-assigning MOVABLE future reservations
 * among the type's serviceable rooms frees one room for the full span — and
 * returns the exact move list. Pure planner: never writes; the caller applies
 * (or discards) the plan, so the same engine serves the importer, dry-runs,
 * and future conflict-center suggestions.
 *
 * Never crosses room types (a guest keeps the product they booked) and never
 * touches a stay that has started: checked-in, past its check-in date, or
 * marked no-show. Returns null when the type is genuinely oversold (no
 * conflict-free assignment exists) or the heuristic cannot find one — the
 * caller then falls back to today's behaviour (park as MBI-BOOKIM), so the
 * planner can only ever reduce overbooking flags, not add them.
 */
class RoomReshuffleService
{
    /** More moves than this is churn, not a fix — treat as infeasible. */
    private const MAX_MOVES = 20;

    /**
     * Plan how to free one room of the type for [checkIn, checkOut).
     *
     * @param  array<int>  $excludeRoomIds  rooms that may neither be freed nor receive moves
     *                                      (e.g. rooms already used by sibling rows of a
     *                                      multi-room booking mid-import); their reservations
     *                                      are implicitly pinned
     * @return ?array{room_id: int, moves: array<int, array{reservation_id: int, from_room_id: int, to_room_id: int}>}
     */
    public function planForIncoming(int $roomTypeId, ?string $checkIn, ?string $checkOut, array $excludeRoomIds = []): ?array
    {
        if (! $this->plannableSpan($checkIn, $checkOut)) {
            return null;
        }

        [$roomIds, $pinned, $movable] = $this->loadTypeState($roomTypeId, $excludeRoomIds);
        if ($roomIds === []) {
            return null;
        }

        return $this->freeRoomWithMinimalEvictions($roomIds, $pinned, $movable, $checkIn, $checkOut)
            ?? $this->globalReassignment($roomIds, $pinned, $movable, $checkIn, $checkOut);
    }

    /**
     * The FREE room (no moves involved) where [checkIn, checkOut) fits with the
     * least slack around it — placement that packs new stays against existing
     * ones so long free runs survive for long bookings. Null when no room is
     * free for the span or the span is unplannable (missing dates / started in
     * the past); the caller then falls back to its own placement.
     */
    public function bestFreeRoom(int $roomTypeId, ?string $checkIn, ?string $checkOut, array $excludeRoomIds = []): ?int
    {
        if (! $this->plannableSpan($checkIn, $checkOut)) {
            return null;
        }

        [$roomIds, $pinned, $movable] = $this->loadTypeState($roomTypeId, $excludeRoomIds);
        $occupied = $pinned;
        foreach ($movable as $m) {
            $occupied[$m['room_id']][] = $m;
        }

        return $this->bestFitRoom(['in' => $checkIn, 'out' => $checkOut], $roomIds, $occupied);
    }

    /**
     * A span that already started is never planned: its past nights collide
     * with stays this planner deliberately does not load (check-out before
     * today), so any plan for it would be built on incomplete occupancy.
     */
    private function plannableSpan(?string $checkIn, ?string $checkOut): bool
    {
        return $checkIn && $checkOut && $checkIn < $checkOut && $checkIn >= today()->toDateString();
    }

    /**
     * Serviceable rooms of the type (minus exclusions) and their upcoming stays,
     * split into pinned (never moved) and movable.
     *
     * @return array{0: array<int, int>, 1: array<int, array<int, array{in: string, out: string}>>, 2: array<int, array{in: string, out: string, id: int, room_id: int}>}
     */
    private function loadTypeState(int $roomTypeId, array $excludeRoomIds): array
    {
        $rooms = Room::where('room_type_id', $roomTypeId)
            ->where('status', '!=', 'maintenance')
            ->whereNotIn('id', $excludeRoomIds ?: [0])
            ->orderByRaw('LENGTH(room_number), room_number')
            ->get(['id', 'room_number']);

        $today = today()->toDateString();
        // Stays already over cannot collide with anything upcoming.
        $reservations = Reservation::whereIn('room_id', $rooms->pluck('id'))
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_out_date', '>', $today)
            ->get(['id', 'room_id', 'status', 'check_in_date', 'check_out_date', 'no_show_at']);

        $pinned = []; // room id => [['in' => Y-m-d, 'out' => Y-m-d], ...]
        $movable = []; // [['id' => res id, 'room_id' => current, 'in' => ..., 'out' => ...], ...]
        foreach ($rooms as $room) {
            $pinned[$room->id] = [];
        }
        foreach ($reservations as $res) {
            $interval = [
                'in' => $res->check_in_date->toDateString(),
                'out' => $res->check_out_date->toDateString(),
            ];
            $isMovable = in_array($res->status, ['pending', 'confirmed'], true)
                && $res->no_show_at === null
                && $interval['in'] >= $today;
            if ($isMovable) {
                $movable[] = $interval + ['id' => $res->id, 'room_id' => $res->room_id];
            } else {
                $pinned[$res->room_id][] = $interval;
            }
        }

        return [$rooms->pluck('id')->all(), $pinned, $movable];
    }

    /**
     * Phase 1 — smallest footprint: pick a room, evict only the movable stays
     * overlapping the incoming span, and fit each eviction into an existing
     * free gap on another room. Rooms are tried in order of fewest evictions.
     */
    private function freeRoomWithMinimalEvictions(array $roomIds, array $pinned, array $movable, string $in, string $out): ?array
    {
        $candidates = [];
        foreach ($roomIds as $roomId) {
            foreach ($pinned[$roomId] as $interval) {
                if ($this->overlaps($interval, $in, $out)) {
                    continue 2; // a started stay blocks the span — room cannot be freed
                }
            }
            $evictions = array_values(array_filter($movable, fn (array $m) => $m['room_id'] === $roomId && $this->overlaps($m, $in, $out)));
            $candidates[] = ['room_id' => $roomId, 'evictions' => $evictions];
        }
        usort($candidates, fn (array $a, array $b) => count($a['evictions']) <=> count($b['evictions']));

        foreach ($candidates as $candidate) {
            $freed = $candidate['room_id'];
            // Occupancy per room if this candidate is chosen: everything except
            // the evictions, with the freed room blocked for the incoming span.
            $occupied = $pinned;
            $evictedIds = array_column($candidate['evictions'], 'id');
            foreach ($movable as $m) {
                if (! in_array($m['id'], $evictedIds, true)) {
                    $occupied[$m['room_id']][] = $m;
                }
            }
            $occupied[$freed][] = ['in' => $in, 'out' => $out];

            $moves = [];
            foreach ($this->sortByStart($candidate['evictions']) as $evicted) {
                $target = $this->bestFitRoom($evicted, $roomIds, $occupied, [$freed]);
                if ($target === null) {
                    continue 2; // this room cannot be emptied — try the next candidate
                }
                $occupied[$target][] = $evicted;
                $moves[] = ['reservation_id' => $evicted['id'], 'from_room_id' => $evicted['room_id'], 'to_room_id' => $target];
            }
            if (count($moves) > self::MAX_MOVES) {
                continue;
            }

            return ['room_id' => $freed, 'moves' => $moves];
        }

        return null;
    }

    /**
     * Phase 2 — fallback when no single room can be emptied into existing gaps:
     * re-place ALL movable stays plus the incoming one from scratch (greedy by
     * check-in, each preferring its current room), then diff against the current
     * assignment. Finds chain solutions phase 1 cannot see; may move more.
     */
    private function globalReassignment(array $roomIds, array $pinned, array $movable, string $in, string $out): ?array
    {
        $intervals = $this->sortByStart(array_merge(
            $movable,
            [['id' => null, 'room_id' => null, 'in' => $in, 'out' => $out]],
        ));

        $occupied = $pinned;
        $incomingRoom = null;
        $moves = [];
        foreach ($intervals as $interval) {
            $current = $interval['room_id'];
            if ($current !== null && $this->fits($interval, $occupied[$current])) {
                $occupied[$current][] = $interval;

                continue;
            }
            $target = $this->bestFitRoom($interval, $roomIds, $occupied);
            if ($target === null) {
                return null; // genuinely oversold (or beyond this heuristic)
            }
            $occupied[$target][] = $interval;
            if ($interval['id'] === null) {
                $incomingRoom = $target;
            } else {
                $moves[] = ['reservation_id' => $interval['id'], 'from_room_id' => $current, 'to_room_id' => $target];
            }
        }

        if ($incomingRoom === null || count($moves) > self::MAX_MOVES) {
            return null;
        }

        return ['room_id' => $incomingRoom, 'moves' => $moves];
    }

    /**
     * The room where the interval fits with the least slack around it (tightest
     * gap first, stable room order as tiebreak) — keeps long free spans intact
     * for future arrivals.
     */
    private function bestFitRoom(array $interval, array $roomIds, array $occupied, array $exclude = []): ?int
    {
        $best = null;
        $bestSlack = PHP_INT_MAX;
        foreach ($roomIds as $roomId) {
            if (in_array($roomId, $exclude, true) || ! $this->fits($interval, $occupied[$roomId])) {
                continue;
            }
            $slack = $this->slackAround($interval, $occupied[$roomId]);
            if ($slack < $bestSlack) {
                $best = $roomId;
                $bestSlack = $slack;
            }
        }

        return $best;
    }

    private function fits(array $interval, array $roomIntervals): bool
    {
        foreach ($roomIntervals as $other) {
            if ($this->overlaps($other, $interval['in'], $interval['out'])) {
                return false;
            }
        }

        return true;
    }

    /** Days of dead space this placement leaves between its two neighbours. */
    private function slackAround(array $interval, array $roomIntervals): int
    {
        $prevEnd = null;
        $nextStart = null;
        foreach ($roomIntervals as $other) {
            if ($other['out'] <= $interval['in'] && ($prevEnd === null || $other['out'] > $prevEnd)) {
                $prevEnd = $other['out'];
            }
            if ($other['in'] >= $interval['out'] && ($nextStart === null || $other['in'] < $nextStart)) {
                $nextStart = $other['in'];
            }
        }
        // ~90-day horizon when unbounded, so an empty room scores worse than a snug gap.
        $before = $prevEnd === null ? 90 : $this->daysBetween($prevEnd, $interval['in']);
        $after = $nextStart === null ? 90 : $this->daysBetween($interval['out'], $nextStart);

        return $before + $after;
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
    }

    /** Hotel overlap: the checkout day is free for the next check-in. */
    private function overlaps(array $interval, string $in, string $out): bool
    {
        return $interval['in'] < $out && $interval['out'] > $in;
    }

    private function sortByStart(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b) => [$a['in'], $b['out']] <=> [$b['in'], $a['out']]);

        return $intervals;
    }
}
