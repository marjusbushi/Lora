<?php

namespace App\Services\Reporting;

use App\Models\MaintenanceIssue;
use App\Models\Reservation;

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

        $overdueArrivals = Reservation::query()
            ->whereDate('check_in_date', '<', today()->toDateString())
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNull('no_show_at')
            ->with(['room:id,room_number', 'guest:id,first_name,last_name'])
            ->orderBy('check_in_date')
            ->get(['id', 'room_id', 'guest_id', 'check_in_date', 'status']);
        $overdueDepartures = Reservation::query()
            ->whereDate('check_out_date', '<', today()->toDateString())
            ->where('status', 'checked_in')
            ->with(['room:id,room_number', 'guest:id,first_name,last_name'])
            ->orderBy('check_out_date')
            ->get(['id', 'room_id', 'guest_id', 'check_out_date', 'status']);

        $overdueActions = $overdueArrivals
            ->map(fn (Reservation $reservation) => [
                'key' => 'overdue-arrival-'.$reservation->id,
                'kind' => 'overdue_arrival',
                'severity' => 'error',
                'reservation_id' => $reservation->id,
                'guest' => $includeGuestDetails
                    ? (trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: '—')
                    : null,
                'room' => $reservation->room?->room_number,
                'days_overdue' => (int) $reservation->check_in_date->startOfDay()->diffInDays(today()),
            ])
            ->concat($overdueDepartures->map(fn (Reservation $reservation) => [
                'key' => 'overdue-departure-'.$reservation->id,
                'kind' => 'overdue_departure',
                'severity' => 'error',
                'reservation_id' => $reservation->id,
                'guest' => $includeGuestDetails
                    ? (trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: '—')
                    : null,
                'room' => $reservation->room?->room_number,
                'days_overdue' => (int) $reservation->check_out_date->startOfDay()->diffInDays(today()),
            ]))
            ->take(6);

        $readinessCandidates = collect($readiness['rooms'])
            ->filter(fn (array $room) => in_array($room['state'], ['unassigned', 'maintenance', 'cleaning_for_arrival', 'turnover'], true)
                || ($room['state'] === 'occupied' && $room['arrival']));
        $actions = $readinessCandidates
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

        $departureCandidates = $departures
            ->filter(fn (array $row) => $row['status'] === 'checked_in' && ((float) $row['balance'] > 0 || (int) ($row['open_pos_count'] ?? 0) > 0));
        $departureActions = $departureCandidates
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

        $maintenanceCandidates = $includeMaintenanceDetails
            ? $activeMaintenance->filter(fn (MaintenanceIssue $issue) => $issue->due_at?->isPast() || $issue->priority === 'critical')
            : collect();
        $maintenanceActions = $maintenanceCandidates
                ->take(6)
                ->map(fn (MaintenanceIssue $issue) => [
                    'key' => 'maintenance-'.$issue->id,
                    'kind' => 'maintenance',
                    'severity' => $issue->due_at?->isPast() ? 'error' : 'warning',
                    'maintenance_id' => $issue->id,
                    'title' => $issue->title,
                    'room' => $issue->room?->room_number,
                    'priority' => $issue->priority,
                ]);

        // The rooms behind readiness.attention (remaining arrivals whose room
        // is not ready): room number, or the guest name while unassigned.
        $attentionRooms = collect($readiness['rooms'])
            ->filter(fn (array $room) => in_array($room['arrival']['status'] ?? null, ['pending', 'confirmed'], true)
                && $room['state'] !== 'ready')
            ->map(fn (array $room) => $room['room_number'] ?? ($room['arrival']['guest'] ?? '—'))
            ->values();

        $shownActions = $overdueActions
            ->concat($actions)
            ->concat($departureActions)
            ->concat($maintenanceActions)
            ->sortBy(fn (array $action) => match (true) {
                in_array($action['kind'], ['overdue_arrival', 'overdue_departure'], true) => 0,
                $action['severity'] === 'error' => 1,
                default => 2,
            })
            ->take(15)
            ->values();
        $actionCandidates = $overdueArrivals->count() + $overdueDepartures->count()
            + $readinessCandidates->count() + $departureCandidates->count() + $maintenanceCandidates->count();

        return [
            'as_of' => now()->toIso8601String(),
            'flow' => [
                'arrivals_total' => $arrivals->count(),
                'arrivals_remaining' => $arrivals->whereIn('status', ['pending', 'confirmed'])->count(),
                'arrivals_completed' => $arrivals->whereIn('status', ['checked_in', 'checked_out'])->count(),
                'arrivals_overdue' => $overdueArrivals->count(),
                'departures_total' => $departures->count(),
                'departures_remaining' => $departures->where('status', 'checked_in')->count(),
                'departures_completed' => $departures->where('status', 'checked_out')->count(),
                'departures_overdue' => $overdueDepartures->count(),
                'in_house_stays' => $inHouse->count(),
                'in_house_pax' => $inHouse->sum('pax'),
                'departure_balance' => round((float) $departures->where('status', 'checked_in')->sum(fn (array $row) => max(0, (float) $row['balance'])), 2),
                'open_pos' => (int) $departures->where('status', 'checked_in')->sum('open_pos_count'),
            ],
            'readiness' => $readiness['summary'] + [
                'states' => $readiness['states'],
                'attention_rooms' => $attentionRooms->all(),
            ],
            'maintenance' => [
                'open' => $openMaintenance->count(),
                'overdue' => $activeMaintenance->filter(fn (MaintenanceIssue $issue) => $issue->due_at?->lt($now))->count(),
                'critical' => $activeMaintenance->where('priority', 'critical')->count(),
                'blocked_rooms' => $readiness['summary']['maintenance'],
            ],
            'actions' => $shownActions->all(),
            'actions_truncated' => max(0, $actionCandidates - $shownActions->count()),
        ];
    }
}
