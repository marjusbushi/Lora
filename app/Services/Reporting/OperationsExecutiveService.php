<?php

namespace App\Services\Reporting;

use App\Models\MaintenanceIssue;

final class OperationsExecutiveService
{
    public function __construct(
        private readonly GuestMovementService $guestMovements,
        private readonly RoomReadinessService $roomReadiness,
    ) {}

    /** @return array{as_of:string,flow:array,readiness:array,maintenance:array,actions:array} */
    public function snapshot(
        bool $includeGuestDetails = true,
        bool $includeHousekeepingDetails = true,
        bool $includeMaintenanceDetails = true,
    ): array {
        $period = new ReportingPeriod(today()->toDateString(), today()->toDateString());
        $movements = $this->guestMovements->summary($period);
        $readiness = $this->roomReadiness->snapshot($includeGuestDetails, $includeHousekeepingDetails);
        $openMaintenance = MaintenanceIssue::query()
            ->whereNotIn('status', ['verified', 'closed'])
            ->with('room:id,room_number')
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_at')
            ->get(['id', 'room_id', 'title', 'priority', 'status', 'due_at']);

        $arrivals = collect($movements['arrivals']);
        $departures = collect($movements['departures']);
        $inHouse = collect($movements['in_house']);
        $now = now();
        $activeMaintenance = $openMaintenance->whereIn('status', ['reported', 'assigned', 'in_progress']);
        $actions = collect($readiness['rooms'])
            ->filter(fn (array $room) => in_array($room['state'], ['unassigned', 'maintenance', 'cleaning_for_arrival', 'turnover'], true)
                || ($room['state'] === 'occupied' && $room['arrival']))
            ->take(8)
            ->map(fn (array $room) => [
                'key' => $room['key'],
                'kind' => 'readiness',
                'severity' => in_array($room['state'], ['unassigned', 'maintenance'], true) ? 'error' : 'warning',
                'state' => $room['state'],
                'room' => $room['room_number'],
                'reservation_id' => $room['arrival']['id'] ?? null,
                'guest' => $room['arrival']['guest'] ?? null,
            ]);

        $departureActions = $departures
            ->filter(fn (array $row) => $row['status'] === 'checked_in' && ((float) $row['balance'] > 0 || (int) ($row['open_pos_count'] ?? 0) > 0))
            ->take(6)
            ->map(fn (array $row) => [
                'key' => 'departure-'.$row['id'],
                'kind' => 'departure',
                'severity' => 'error',
                'reservation_id' => $row['id'],
                'guest' => $includeGuestDetails ? $row['guest'] : null,
                'room' => $row['room'],
                'balance' => $row['balance'],
                'open_pos_count' => $row['open_pos_count'] ?? 0,
            ]);

        $maintenanceActions = $includeMaintenanceDetails
            ? $activeMaintenance->filter(fn (MaintenanceIssue $issue) => $issue->due_at?->isPast() || $issue->priority === 'critical')
                ->take(6)
                ->map(fn (MaintenanceIssue $issue) => [
                    'key' => 'maintenance-'.$issue->id,
                    'kind' => 'maintenance',
                    'severity' => $issue->due_at?->isPast() ? 'error' : 'warning',
                    'maintenance_id' => $issue->id,
                    'title' => $issue->title,
                    'room' => $issue->room?->room_number,
                    'priority' => $issue->priority,
                ])
            : collect();

        return [
            'as_of' => now()->toIso8601String(),
            'flow' => [
                'arrivals_total' => $arrivals->count(),
                'arrivals_remaining' => $arrivals->whereIn('status', ['pending', 'confirmed'])->count(),
                'arrivals_completed' => $arrivals->whereIn('status', ['checked_in', 'checked_out'])->count(),
                'departures_total' => $departures->count(),
                'departures_remaining' => $departures->where('status', 'checked_in')->count(),
                'departures_completed' => $departures->where('status', 'checked_out')->count(),
                'in_house_stays' => $inHouse->count(),
                'in_house_pax' => $inHouse->sum('pax'),
                'departure_balance' => round((float) $departures->where('status', 'checked_in')->sum(fn (array $row) => max(0, (float) $row['balance'])), 2),
                'open_pos' => (int) $departures->where('status', 'checked_in')->sum('open_pos_count'),
            ],
            'readiness' => $readiness['summary'] + ['states' => $readiness['states']],
            'maintenance' => [
                'open' => $openMaintenance->count(),
                'overdue' => $activeMaintenance->filter(fn (MaintenanceIssue $issue) => $issue->due_at?->lt($now))->count(),
                'critical' => $activeMaintenance->where('priority', 'critical')->count(),
                'blocked_rooms' => $readiness['summary']['maintenance'],
            ],
            'actions' => $actions
                ->concat($departureActions)
                ->concat($maintenanceActions)
                ->sortBy(fn (array $action) => $action['severity'] === 'error' ? 0 : 1)
                ->take(15)
                ->values()
                ->all(),
        ];
    }
}
