<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Support\Collection;

class ReservationConflictService
{
    private const ACTIVE_STATUSES = ['pending', 'confirmed', 'checked_in'];

    public function detect(string $startDate, string $endDate): array
    {
        $reservations = Reservation::query()
            ->select('id', 'room_id', 'guest_id', 'check_in_date', 'check_out_date', 'status', 'adults', 'channel', 'channel_ref', 'created_at')
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
                    'reservations' => $group->map(fn (Reservation $reservation) => [
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
                        'suggested_rooms' => $reservation->id !== $keeper?->id
                            && in_array($reservation->status, ['pending', 'confirmed'], true)
                                ? $this->suggestRooms($reservation, $rooms)
                                : [],
                    ])->all(),
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

    private function suggestRooms(Reservation $reservation, Collection $rooms): array
    {
        $currentRoom = $rooms->firstWhere('id', $reservation->room_id);

        return $rooms
            ->filter(fn (Room $room) => $room->id !== $reservation->room_id
                && $room->status !== 'maintenance'
                && ($room->roomType?->max_occupancy ?? 0) >= $reservation->adults)
            ->sort(function (Room $left, Room $right) use ($currentRoom) {
                $leftSame = $left->room_type_id === $currentRoom?->room_type_id;
                $rightSame = $right->room_type_id === $currentRoom?->room_type_id;

                return $leftSame === $rightSame
                    ? strnatcasecmp($left->room_number, $right->room_number)
                    : ($leftSame ? -1 : 1);
            })
            ->filter(fn (Room $room) => Reservation::isRoomAvailable(
                $room->id,
                $reservation->check_in_date->toDateString(),
                $reservation->check_out_date->toDateString(),
                $reservation->id
            ))
            ->take(3)
            ->map(fn (Room $room) => [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'room_type' => $room->roomType?->name,
                'same_type' => $room->room_type_id === $currentRoom?->room_type_id,
            ])
            ->values()
            ->all();
    }
}
