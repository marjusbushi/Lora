<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Support\Collection;

class ReservationConflictService
{
    private const ACTIVE_STATUSES = ['pending', 'confirmed', 'checked_in'];

    public function __construct(private RoomReshuffleService $reshuffler)
    {
    }

    public function detect(string $startDate, string $endDate): array
    {
        $reservations = Reservation::query()
            ->select('id', 'room_id', 'booked_room_type_id', 'guest_id', 'check_in_date', 'check_out_date', 'status', 'adults', 'children', 'channel', 'channel_ref', 'created_at')
            ->with('guest:id,first_name,last_name')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('check_in_date', '<=', $endDate)
            ->where('check_out_date', '>', $startDate)
            ->orderBy('room_id')
            ->orderBy('check_in_date')
            ->orderBy('id')
            ->get();

        $rooms = Room::query()
            ->select('id', 'room_number', 'room_type_id', 'status')
            ->with('roomType:id,name,max_occupancy')
            ->get();
        $roomsById = $rooms->keyBy('id');
        $conflicts = [];

        foreach ($reservations->groupBy('room_id') as $roomId => $roomReservations) {
            $ordered = $roomReservations->values();
            $room = $roomsById->get((int) $roomId);
            if (! $room) {
                continue;
            }

            foreach ($this->overlappingGroups($ordered) as $group) {
                $keeper = $this->keeperReservation($group);
                [$conflictStart, $conflictEnd] = $this->conflictPeriod($group);
                $ids = $group->pluck('id')->implode('-');

                $conflicts[] = [
                    'id' => "room-{$room->id}-{$ids}",
                    'room_id' => $room->id,
                    'room_number' => $room->room_number,
                    'room_type' => $room->roomType?->name,
                    'start_date' => $conflictStart,
                    'end_date' => $conflictEnd,
                    'reservations' => $group->map(function (Reservation $reservation) use ($keeper, $rooms) {
                        $movable = $reservation->id !== $keeper?->id
                            && in_array($reservation->status, ['pending', 'confirmed'], true);
                        $suggestions = $movable
                            ? $this->suggestionsFor($reservation, $rooms)
                            : ['suggested_rooms' => [], 'reshuffle_plan' => null, 'cross_type_rooms' => []];

                        return [
                            'id' => $reservation->id,
                            'room_id' => $reservation->room_id,
                            'check_in_date' => $reservation->check_in_date->toDateString(),
                            'check_out_date' => $reservation->check_out_date->toDateString(),
                            'status' => $reservation->status,
                            'channel' => $reservation->channel,
                            'channel_ref' => $reservation->channel_ref,
                            'guest' => $reservation->guest ? [
                                'first_name' => $reservation->guest->first_name,
                                'last_name' => $reservation->guest->last_name,
                            ] : null,
                            'keep_in_room' => $reservation->id === $keeper?->id || $reservation->status === 'checked_in',
                            ...$suggestions,
                        ];
                    })->all(),
                ];
            }
        }

        return $conflicts;
    }

    private function overlappingGroups(Collection $reservations): Collection
    {
        $groups = collect();
        $current = collect();
        $currentEnd = null;

        foreach ($reservations as $reservation) {
            if ($current->isEmpty() || $reservation->check_in_date->lessThan($currentEnd)) {
                $current->push($reservation);
                if ($currentEnd === null || $reservation->check_out_date->greaterThan($currentEnd)) {
                    $currentEnd = $reservation->check_out_date;
                }

                continue;
            }

            if ($current->count() > 1) {
                $groups->push($current);
            }
            $current = collect([$reservation]);
            $currentEnd = $reservation->check_out_date;
        }

        if ($current->count() > 1) {
            $groups->push($current);
        }

        return $groups;
    }

    /** @return array{string, string} */
    private function conflictPeriod(Collection $reservations): array
    {
        $starts = collect();
        $ends = collect();

        for ($left = 0; $left < $reservations->count(); $left++) {
            for ($right = $left + 1; $right < $reservations->count(); $right++) {
                $first = $reservations[$left];
                $second = $reservations[$right];
                if ($second->check_in_date->greaterThanOrEqualTo($first->check_out_date)) {
                    continue;
                }

                $starts->push(($first->check_in_date->greaterThan($second->check_in_date)
                    ? $first->check_in_date
                    : $second->check_in_date)->toDateString());
                $ends->push(($first->check_out_date->lessThan($second->check_out_date)
                    ? $first->check_out_date
                    : $second->check_out_date)->toDateString());
            }
        }

        return [$starts->min(), $ends->max()];
    }

    public function hasConflict(Reservation $reservation): bool
    {
        return Reservation::query()
            ->whereKeyNot($reservation->id)
            ->where('room_id', $reservation->room_id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('check_in_date', '<', $reservation->check_out_date->toDateString())
            ->where('check_out_date', '>', $reservation->check_in_date->toDateString())
            ->exists();
    }

    private function keeperReservation(Collection $reservations): ?Reservation
    {
        return $reservations->sortBy(fn (Reservation $reservation) => sprintf(
            '%d-%010d-%010d',
            $reservation->status === 'checked_in' ? 0 : 1,
            $reservation->created_at?->timestamp ?? 0,
            $reservation->id
        ))
            ->first();
    }

    /**
     * Suggestions for re-homing one conflict party, type-strict by design:
     * the guest keeps the product they BOOKED (booked_room_type_id; current
     * room's type only for legacy rows). Primary list = free same-type rooms.
     * When none exists, the answer is a same-type move CHAIN from the reshuffle
     * engine (which may relocate the other party too) — and only as an explicit
     * escape valve, rooms of other types (a product change staff must own).
     *
     * @return array{suggested_rooms: array, reshuffle_plan: ?array, cross_type_rooms: array}
     */
    private function suggestionsFor(Reservation $reservation, Collection $rooms): array
    {
        $baselineTypeId = $reservation->booked_room_type_id
            ?? $rooms->firstWhere('id', $reservation->room_id)?->room_type_id;
        $guestCount = (int) $reservation->adults + (int) $reservation->children;

        $fits = fn (Room $room) => $room->id !== $reservation->room_id
            && $room->status !== 'maintenance'
            && ($room->roomType?->max_occupancy ?? 0) >= $guestCount
            && Reservation::isRoomAvailable(
                $room->id,
                $reservation->check_in_date->toDateString(),
                $reservation->check_out_date->toDateString(),
                $reservation->id
            );

        $sameType = $rooms
            ->filter(fn (Room $room) => $room->room_type_id === $baselineTypeId)
            ->filter($fits)
            ->sortBy('room_number', SORT_NATURAL | SORT_FLAG_CASE)
            ->take(3)
            ->map(fn (Room $room) => $this->roomPayload($room, true))
            ->values()
            ->all();

        if ($sameType !== []) {
            return ['suggested_rooms' => $sameType, 'reshuffle_plan' => null, 'cross_type_rooms' => []];
        }

        return [
            'suggested_rooms' => [],
            'reshuffle_plan' => $this->reshufflePlan($reservation, $baselineTypeId),
            'cross_type_rooms' => $rooms
                ->filter(fn (Room $room) => $room->room_type_id !== $baselineTypeId)
                ->filter($fits)
                ->sortBy('room_number', SORT_NATURAL | SORT_FLAG_CASE)
                ->take(3)
                ->map(fn (Room $room) => $this->roomPayload($room, false))
                ->values()
                ->all(),
        ];
    }

    /**
     * The same-type move chain that frees a room for this stay, or null. Spans
     * already started are not planned (engine guard) — those conflicts fall to
     * the cross-type valve or manual handling. A zero-move plan is discarded:
     * a genuinely free same-type room belongs to the primary list, and the two
     * occupancy models disagreeing is not a reason to render an empty chain.
     */
    private function reshufflePlan(Reservation $reservation, ?int $baselineTypeId): ?array
    {
        if (! $baselineTypeId) {
            return null;
        }

        $plan = $this->reshuffler->planForIncoming(
            $baselineTypeId,
            $reservation->check_in_date->toDateString(),
            $reservation->check_out_date->toDateString(),
            [],
            [$reservation->id],
        );
        if (! $plan || $plan['moves'] === []) {
            return null;
        }

        $roomNumbers = Room::whereIn(
            'id',
            collect($plan['moves'])->flatMap(fn (array $m) => [$m['from_room_id'], $m['to_room_id']])->push($plan['room_id']),
        )->pluck('room_number', 'id');
        $movedStays = Reservation::whereIn('id', array_column($plan['moves'], 'reservation_id'))
            ->with('guest:id,first_name,last_name')
            ->get()
            ->keyBy('id');

        return [
            'room' => [
                'id' => $plan['room_id'],
                'room_number' => $roomNumbers[$plan['room_id']] ?? (string) $plan['room_id'],
            ],
            'moves' => collect($plan['moves'])->map(fn (array $move) => [
                'reservation_id' => $move['reservation_id'],
                'guest_name' => trim(
                    ($movedStays[$move['reservation_id']]?->guest?->first_name ?? '')
                    .' '
                    .($movedStays[$move['reservation_id']]?->guest?->last_name ?? ''),
                ),
                'from_room_number' => $roomNumbers[$move['from_room_id']] ?? (string) $move['from_room_id'],
                'to_room_number' => $roomNumbers[$move['to_room_id']] ?? (string) $move['to_room_id'],
            ])->all(),
        ];
    }

    private function roomPayload(Room $room, bool $sameType): array
    {
        return [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'room_type' => $room->roomType?->name,
            'same_type' => $sameType,
        ];
    }
}
