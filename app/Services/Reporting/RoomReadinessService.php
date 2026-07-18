<?php

namespace App\Services\Reporting;

use App\Models\CleaningTask;
use App\Models\Reservation;
use App\Models\Room;

final class RoomReadinessService
{
    /** @return array{as_of:string,summary:array,states:array,rooms:array} */
    public function snapshot(bool $includeGuestDetails = true, bool $includeHousekeepingDetails = true): array
    {
        $today = today();
        $rooms = Room::query()
            ->with('roomType:id,name')
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get(['id', 'room_number', 'floor', 'status', 'room_type_id']);
        $arrivals = Reservation::query()
            ->whereDate('check_in_date', $today)
            ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
            ->whereNull('no_show_at')
            ->when($includeGuestDetails, fn ($query) => $query->with('guest:id,first_name,last_name'))
            ->orderByRaw("CASE WHEN status IN ('pending', 'confirmed') THEN 0 ELSE 1 END")
            ->orderBy('eta')
            ->get(['id', 'room_id', 'guest_id', 'status', 'eta']);
        $departures = Reservation::query()
            ->whereDate('check_out_date', $today)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->when($includeGuestDetails, fn ($query) => $query->with('guest:id,first_name,last_name'))
            ->orderByRaw("CASE WHEN status = 'checked_in' THEN 0 ELSE 1 END")
            ->orderBy('etd')
            ->get(['id', 'room_id', 'guest_id', 'status', 'etd']);
        $tasks = CleaningTask::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->when($includeHousekeepingDetails, fn ($query) => $query->with('assignedUser:id,name'))
            ->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get(['id', 'room_id', 'assigned_to', 'status', 'priority', 'type']);

        $arrivalsByRoom = $arrivals->whereNotNull('room_id')->groupBy('room_id')->map->first();
        $departuresByRoom = $departures->whereNotNull('room_id')->groupBy('room_id')->map->first();
        $tasksByRoom = $tasks->whereNotNull('room_id')->groupBy('room_id')->map->first();
        $rows = $rooms->map(function (Room $room) use ($arrivalsByRoom, $departuresByRoom, $tasksByRoom, $includeGuestDetails, $includeHousekeepingDetails) {
            $arrival = $arrivalsByRoom->get($room->id);
            $departure = $departuresByRoom->get($room->id);
            $task = $tasksByRoom->get($room->id);
            $state = $this->state($room, $arrival, $departure, $task);

            return [
                'key' => 'room-'.$room->id,
                'room_id' => $room->id,
                'room_number' => $room->room_number,
                'room_type' => $room->roomType?->name,
                'floor' => $room->floor,
                'state' => $state,
                'priority' => $this->priority($state),
                'arrival' => $this->movement($arrival, 'eta', $includeGuestDetails),
                'departure' => $this->movement($departure, 'etd', $includeGuestDetails),
                'cleaning' => $task ? [
                    'id' => $task->id,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'type' => $task->type,
                    'assignee' => $includeHousekeepingDetails ? $task->assignedUser?->name : null,
                ] : null,
            ];
        });

        $unassigned = $arrivals->whereNull('room_id')->whereIn('status', ['pending', 'confirmed'])->map(fn (Reservation $arrival) => [
            'key' => 'arrival-'.$arrival->id,
            'room_id' => null,
            'room_number' => null,
            'room_type' => null,
            'floor' => null,
            'state' => 'unassigned',
            'priority' => 0,
            'arrival' => $this->movement($arrival, 'eta', $includeGuestDetails),
            'departure' => null,
            'cleaning' => null,
        ]);
        $rows = $rows->concat($unassigned)->sortBy([
            ['priority', 'asc'],
            ['floor', 'asc'],
            ['room_number', 'asc'],
        ])->values();
        $remainingArrivals = $arrivals->whereIn('status', ['pending', 'confirmed']);
        $readyArrivalIds = $rows->where('state', 'ready')->pluck('arrival.id')->filter()->unique();
        $attention = $remainingArrivals->pluck('id')->diff($readyArrivalIds)->count();

        return [
            'as_of' => now()->toIso8601String(),
            'summary' => [
                'total_rooms' => $rooms->count(),
                'arrivals_remaining' => $remainingArrivals->count(),
                'ready_arrivals' => $readyArrivalIds->count(),
                'ready_rate' => $remainingArrivals->isEmpty() ? 100.0 : round($readyArrivalIds->count() / $remainingArrivals->count() * 100, 1),
                'attention' => $attention,
                'cleaning' => $rows->whereIn('state', ['cleaning', 'cleaning_for_arrival'])->count(),
                'maintenance' => $rows->where('state', 'maintenance')->count(),
                'turnovers' => $rows->whereIn('state', ['turnover', 'departure_pending'])->count(),
            ],
            'states' => $rows->countBy('state')->map(fn (int $value, string $key) => ['key' => $key, 'value' => $value])->values()->all(),
            'rooms' => $rows->all(),
        ];
    }

    private function state(Room $room, ?Reservation $arrival, ?Reservation $departure, ?CleaningTask $task): string
    {
        if ($arrival?->status === 'checked_in') {
            return 'checked_in';
        }
        if ($room->status === 'maintenance') {
            return 'maintenance';
        }
        if ($departure?->status === 'checked_in') {
            return $arrival ? 'turnover' : 'departure_pending';
        }
        if ($task || $room->status === 'cleaning') {
            return $arrival ? 'cleaning_for_arrival' : 'cleaning';
        }
        if ($arrival && in_array($arrival->status, ['pending', 'confirmed'], true)) {
            return $room->status === 'available' ? 'ready' : 'occupied';
        }

        return $room->status === 'available' ? 'available' : 'occupied';
    }

    private function priority(string $state): int
    {
        return match ($state) {
            'unassigned', 'maintenance', 'cleaning_for_arrival', 'turnover', 'occupied' => 0,
            'departure_pending', 'cleaning' => 1,
            'ready' => 2,
            'checked_in' => 3,
            default => 4,
        };
    }

    private function movement(?Reservation $reservation, string $timeField, bool $includeGuestDetails): ?array
    {
        if (! $reservation) {
            return null;
        }

        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'time' => $reservation->{$timeField},
            'guest' => $includeGuestDetails ? trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") : null,
        ];
    }
}
