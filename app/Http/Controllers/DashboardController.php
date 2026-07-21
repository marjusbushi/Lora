<?php

namespace App\Http\Controllers;

use App\Models\ChannelMapping;
use App\Models\ChannelSyncLog;
use App\Models\CleaningTask;
use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use App\Services\BaseCurrency;
use App\Services\ChannexConfiguration;
use App\Services\OtaSellWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(OtaSellWindow $sellWindow): Response
    {
        $user = request()->user();
        $today = today()->startOfDay();
        $todayString = $today->toDateString();

        $permissions = [
            'view_reservations' => $user->can('view_reservations'),
            'create_reservations' => $user->can('view_reservations') && $user->can('create_reservations'),
            'update_reservations' => $user->can('view_reservations') && $user->can('update_reservations'),
            'view_rooms' => $user->can('view_rooms'),
            'view_housekeeping' => $user->can('view_housekeeping'),
            'view_pos' => $user->can('view_pos_orders'),
            'view_financials' => $user->can('view_reports'),
            // Pricing routes are admin-only, so expose the real route capability.
            'view_pricing' => $user->hasRole('admin'),
        ];

        $rooms = $permissions['view_rooms']
            ? Room::query()
                ->with('roomType:id,name')
                ->orderBy('floor')
                ->orderBy('room_number')
                ->get(['id', 'room_number', 'floor', 'status', 'room_type_id'])
            : collect();

        $sellableRoomIds = $rooms
            ->where('status', '!=', 'maintenance')
            ->pluck('id')
            ->values();
        $sellableRooms = $sellableRoomIds->count();
        $soldTonight = $sellableRooms
            ? Reservation::query()
                ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
                ->whereDate('check_in_date', '<=', $today)
                ->whereDate('check_out_date', '>', $today)
                ->whereIn('room_id', $sellableRoomIds)
                ->distinct()
                ->count('room_id')
            : 0;

        $canViewMovementCounts = $permissions['view_rooms']
            || $permissions['view_reservations']
            || $permissions['view_housekeeping'];
        $arrivalStatusCounts = $canViewMovementCounts && ! $permissions['view_reservations']
            ? Reservation::query()
                ->whereDate('check_in_date', $today)
                ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'checked_out'])
                ->whereNull('no_show_at')
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
            : collect();
        $departureStatusCounts = $canViewMovementCounts && ! $permissions['view_reservations']
            ? Reservation::query()
                ->whereDate('check_out_date', $today)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
            : collect();

        $arrivals = $permissions['view_reservations']
            ? Reservation::query()
                ->whereDate('check_in_date', $today)
                ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'checked_out'])
                ->whereNull('no_show_at')
                ->with([
                    'room:id,room_number,room_type_id,status',
                    'room.roomType:id,name',
                    'guest:id,first_name,last_name',
                ])
                ->orderByRaw("CASE WHEN status IN ('pending', 'confirmed') THEN 0 ELSE 1 END")
                ->orderBy('eta')
                ->get()
            : collect();

        $departures = $permissions['view_reservations']
            ? Reservation::query()
                ->whereDate('check_out_date', $today)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->with([
                    'room:id,room_number,room_type_id,status',
                    'room.roomType:id,name',
                    'guest:id,first_name,last_name',
                ])
                ->orderByRaw("CASE WHEN status = 'checked_in' THEN 0 ELSE 1 END")
                ->orderBy('etd')
                ->get()
            : collect();

        if ($permissions['view_reservations']) {
            $arrivalStatusCounts = $arrivals->countBy('status');
            $departureStatusCounts = $departures->countBy('status');
        }

        $arrivalRoomIds = $permissions['view_reservations']
            ? $arrivals
                ->whereIn('status', ['pending', 'confirmed'])
                ->pluck('room_id')
                ->filter()
                ->unique()
                ->values()
            : ($canViewMovementCounts
            ? Reservation::query()
                ->whereDate('check_in_date', $today)
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereNull('no_show_at')
                ->pluck('room_id')
                ->filter()
                ->unique()
                ->values()
            : collect());

        $cleaningQuery = CleaningTask::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderBy('created_at');
        if ($permissions['view_housekeeping']) {
            $cleaningQuery->with(['room:id,room_number', 'assignedUser:id,name']);
        }
        $openCleaningTasks = ($permissions['view_rooms'] || $permissions['view_housekeeping'])
            ? $cleaningQuery->get()
            : collect();

        $openCleaningRoomIds = $openCleaningTasks
            ->pluck('room_id')
            ->filter()
            ->unique()
            ->values();
        $openCleaningCount = $openCleaningRoomIds->count();
        $rushRoomIds = $openCleaningRoomIds->intersect($arrivalRoomIds)->values();

        $openPos = $permissions['view_pos']
            ? PosOrder::query()
                ->where('status', 'open')
                ->orderByDesc('created_at')
                ->get(['id', 'table_number', 'total_amount', 'created_at'])
            : collect();

        $dueStays = $permissions['view_financials']
            ? Reservation::query()
                ->where('status', 'checked_in')
                ->whereDate('check_out_date', '<=', $today)
                ->get(['id', 'total_amount_base'])
            : collect();
        $dueBalances = $this->balancesFor($dueStays);
        $positiveDueBalances = $dueBalances->filter(fn (float $balance) => $balance > 0.009);

        $movementBalances = $permissions['view_financials']
            ? $this->balancesFor($arrivals->concat($departures)->unique('id')->values())
            : collect();

        $operational = [
            'occupancy_tonight' => [
                'pct' => $sellableRooms ? (int) round($soldTonight / $sellableRooms * 100) : 0,
                'sold' => $soldTonight,
                'sellable' => $sellableRooms,
            ],
            'arrivals' => [
                'total' => (int) $arrivalStatusCounts->sum(),
                'remaining' => (int) ($arrivalStatusCounts['pending'] ?? 0)
                    + (int) ($arrivalStatusCounts['confirmed'] ?? 0),
                'completed' => (int) ($arrivalStatusCounts['checked_in'] ?? 0)
                    + (int) ($arrivalStatusCounts['checked_out'] ?? 0),
            ],
            'departures' => [
                'total' => (int) $departureStatusCounts->sum(),
                'remaining' => (int) ($departureStatusCounts['checked_in'] ?? 0),
                'completed' => (int) ($departureStatusCounts['checked_out'] ?? 0),
            ],
            'housekeeping' => [
                'open' => $openCleaningCount,
                'rush' => $rushRoomIds->count(),
            ],
            'due_today' => [
                'amount' => round((float) $positiveDueBalances->sum(), 2),
                'count' => $positiveDueBalances->count(),
            ],
            'in_house_reservations' => $canViewMovementCounts
                ? Reservation::query()->where('status', 'checked_in')->count()
                : 0,
            'open_pos' => [
                'count' => $openPos->count(),
                'total' => round((float) $openPos->sum('total_amount'), 2),
            ],
        ];

        $otaHealth = $this->otaHealth($sellWindow);
        $tasksByRoom = $openCleaningTasks->groupBy('room_id')->map->first();
        $arrivalsByRoom = $arrivals->groupBy('room_id')->map->first();
        $departuresByRoom = $departures->groupBy('room_id')->map->first();
        $defaultMovementTimes = [
            'arrival' => Setting::get('hotel.check_in_time', '14:00'),
            'departure' => Setting::get('hotel.check_out_time', '11:00'),
        ];
        $roomFlow = $this->roomFlow(
            $rooms,
            $arrivalsByRoom,
            $departuresByRoom,
            $tasksByRoom,
            $rushRoomIds,
            $defaultMovementTimes,
            $movementBalances,
            $permissions,
        );

        return Inertia::render('Dashboard', [
            'permissions' => $permissions,
            'operational' => $operational,
            'otaHealth' => $otaHealth,
            'roomFlow' => $roomFlow,
            'actions' => $this->actions(
                $today,
                $arrivals,
                $openCleaningRoomIds,
                $openPos,
                $otaHealth,
                $permissions,
            ),
            'ownerPulse' => $permissions['view_financials'] ? $this->ownerPulse($today) : null,
            'forecast' => $this->occupancyForecast($today, $sellableRoomIds),
            'currency' => BaseCurrency::symbol(),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function roomFlow(
        Collection $rooms,
        Collection $arrivalsByRoom,
        Collection $departuresByRoom,
        Collection $tasksByRoom,
        Collection $rushRoomIds,
        array $defaultMovementTimes,
        Collection $movementBalances,
        array $permissions,
    ): Collection {
        return $rooms->map(function (Room $room) use (
            $arrivalsByRoom,
            $departuresByRoom,
            $tasksByRoom,
            $rushRoomIds,
            $defaultMovementTimes,
            $movementBalances,
            $permissions,
        ) {
            $arrival = $arrivalsByRoom->get($room->id);
            $departure = $departuresByRoom->get($room->id);
            $task = $tasksByRoom->get($room->id);
            $rush = $rushRoomIds->contains($room->id);

            $row = [
                'room_id' => $room->id,
                'room_number' => $room->room_number,
                'room_type' => $room->roomType?->name,
                'floor' => $room->floor,
                'current_status' => $room->status,
                'priority' => $this->roomFlowPriority($room, $arrival, $departure, $task, $rush),
            ];

            if (($permissions['view_rooms'] || $permissions['view_housekeeping']) && $task) {
                $row['cleaning'] = [
                    'id' => $task->id,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'rush' => $rush,
                ];
                if ($permissions['view_housekeeping']) {
                    $row['cleaning']['assigned_to'] = $task->assignedUser?->name;
                }
            }

            if ($permissions['view_reservations']) {
                $row['departure'] = $departure
                    ? $this->movementRow(
                        $departure,
                        'departure',
                        $movementBalances,
                        $permissions['view_financials'],
                        $defaultMovementTimes,
                    )
                    : null;
                $row['arrival'] = $arrival
                    ? $this->movementRow(
                        $arrival,
                        'arrival',
                        $movementBalances,
                        $permissions['view_financials'],
                        $defaultMovementTimes,
                        $room->status === 'available' && ! $task,
                    )
                    : null;
            }

            return $row;
        })->sortBy([
            ['priority', 'asc'],
            ['floor', 'asc'],
            ['room_number', 'asc'],
        ])->values();
    }

    /** @return array<string, mixed> */
    private function movementRow(
        Reservation $reservation,
        string $kind,
        Collection $balances,
        bool $includeBalance,
        array $defaultMovementTimes,
        ?bool $readyForCheckIn = null,
    ): array {
        $row = [
            'id' => $reservation->id,
            'guest' => trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: 'Mysafir',
            'time' => $kind === 'arrival'
                ? ($reservation->eta ?: $defaultMovementTimes['arrival'])
                : ($reservation->etd ?: $defaultMovementTimes['departure']),
            'status' => $reservation->status,
            'completed' => $kind === 'arrival'
                ? in_array($reservation->status, ['checked_in', 'checked_out'], true)
                : $reservation->status === 'checked_out',
        ];

        if ($includeBalance) {
            $row['balance'] = round((float) ($balances[$reservation->id] ?? 0), 2);
        }
        if ($kind === 'arrival') {
            $row['ready_for_check_in'] = (bool) $readyForCheckIn;
        }

        return $row;
    }

    private function roomFlowPriority(
        Room $room,
        ?Reservation $arrival,
        ?Reservation $departure,
        ?CleaningTask $task,
        bool $rush,
    ): int {
        if ($rush || ($arrival && ($room->status === 'cleaning' || $room->status === 'maintenance' || $task))) {
            return 0;
        }
        if ($room->status === 'maintenance') {
            return 1;
        }
        if ($departure && $departure->status === 'checked_in') {
            return 2;
        }
        if ($arrival && in_array($arrival->status, ['pending', 'confirmed'], true)) {
            return 3;
        }
        if ($room->status === 'cleaning' || $task) {
            return 4;
        }
        if ($room->status === 'occupied') {
            return 5;
        }

        return 6;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function actions(
        Carbon $today,
        Collection $arrivals,
        Collection $openCleaningRoomIds,
        Collection $openPos,
        array $otaHealth,
        array $permissions,
    ): Collection {
        $actions = collect();

        if ($permissions['view_reservations']) {
            $overstays = Reservation::query()
                ->where('status', 'checked_in')
                ->whereDate('check_out_date', '<', $today)
                ->count();
            if ($overstays > 0) {
                $actions->push([
                    'type' => 'overstay',
                    'level' => 'error',
                    'count' => $overstays,
                    'title' => "{$overstays} dalje me afat të kaluar",
                    'detail' => 'Kontrollo statusin dhe pagesën para mbylljes së ditës.',
                    'href' => route('reservations.index', [
                        'status' => 'checked_in',
                        'sort' => 'checkout',
                    ]),
                    'cta' => 'Kontrollo',
                ]);
            }

            $noShowQuery = Reservation::query()
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereNull('no_show_at')
                ->whereDate('check_in_date', '<', $today);
            $noShows = (clone $noShowQuery)->count();
            if ($noShows > 0) {
                $noShowFrom = (clone $noShowQuery)->min('check_in_date');
                $actions->push([
                    'type' => 'no_show',
                    'level' => 'warning',
                    'count' => $noShows,
                    'title' => "{$noShows} mundësi no-show",
                    'detail' => 'Rezervime të pakontrolluara me hyrje të kaluar.',
                    'href' => $permissions['view_financials']
                        ? route('reports.cancellations', [
                            'from' => substr((string) $noShowFrom, 0, 10),
                            'to' => $today->copy()->subDay()->toDateString(),
                        ])
                        : route('reservations.index', ['sort' => 'checkin']),
                    'cta' => 'Kontrollo',
                ]);
            }

            $notReady = $arrivals
                ->whereIn('status', ['pending', 'confirmed'])
                ->filter(fn (Reservation $reservation) => $reservation->room
                    && (in_array($reservation->room->status, ['cleaning', 'maintenance'], true)
                        || $openCleaningRoomIds->contains($reservation->room_id)))
                ->pluck('room_id')
                ->unique()
                ->count();
            if ($notReady > 0) {
                $actions->push([
                    'type' => 'room_not_ready',
                    'level' => 'warning',
                    'count' => $notReady,
                    'title' => "{$notReady} dhomë(a) nuk janë gati",
                    'detail' => 'Kanë mbërritje sot dhe kërkojnë pastrim ose kontroll.',
                    'href' => $permissions['view_housekeeping']
                        ? route('housekeeping.index')
                        : ($permissions['view_rooms'] ? route('rooms.index') : route('reservations.index')),
                    'cta' => 'Hap dhomat',
                ]);
            }
        }

        if ($permissions['view_pos']) {
            $stalePos = $openPos->filter(fn (PosOrder $order) => $order->created_at->lt($today))->count();
            if ($stalePos > 0) {
                $actions->push([
                    'type' => 'stale_pos',
                    'level' => 'warning',
                    'count' => $stalePos,
                    'title' => "{$stalePos} porosi POS të vjetra",
                    'detail' => 'Porosi të hapura nga ditët e kaluara.',
                    'href' => route('pos.index'),
                    'cta' => 'Hap POS',
                ]);
            }

            $shiftDifferences = PosShift::query()
                ->where('status', 'closed')
                ->whereDate('closed_at', $today)
                ->where('over_short', '!=', 0)
                ->count();
            if ($shiftDifferences > 0) {
                $actions->push([
                    'type' => 'cash_difference',
                    'level' => 'warning',
                    'count' => $shiftDifferences,
                    'title' => 'Diferencë në mbylljen e arkës',
                    'detail' => 'Kontrollo turnet e mbyllura sot.',
                    'href' => $permissions['view_financials'] ? route('reports.shifts') : route('pos.index'),
                    'cta' => 'Kontrollo',
                ]);
            }
        }

        if (in_array($otaHealth['status'], ['attention', 'not_configured'], true)) {
            $notConfigured = $otaHealth['status'] === 'not_configured';
            $actions->push([
                'type' => 'channex',
                'level' => 'error',
                'count' => 1,
                'title' => $notConfigured ? 'Channex nuk është konfiguruar' : 'Channex kërkon kontroll',
                'detail' => $notConfigured
                    ? 'Lidhja PMS → Channex mungon në këtë ambient.'
                    : 'Sinkronizimi PMS → Channex nuk është në gjendjen e pritur.',
                'href' => $permissions['view_pricing'] ? route('pricing.index') : null,
                'cta' => $permissions['view_pricing'] ? 'Hap cilësimet' : null,
            ]);
        }

        $levelPriority = ['error' => 0, 'warning' => 1, 'info' => 2];
        $typePriority = [
            'overstay' => 0,
            'channex' => 1,
            'room_not_ready' => 2,
            'no_show' => 3,
            'cash_difference' => 4,
            'stale_pos' => 5,
        ];

        return $actions->sortBy(fn (array $action) => sprintf(
            '%02d-%02d',
            $levelPriority[$action['level']] ?? 9,
            $typePriority[$action['type']] ?? 9,
        ))->values();
    }

    /** @return array<string, mixed> */
    private function otaHealth(OtaSellWindow $sellWindow): array
    {
        $configured = app(ChannexConfiguration::class)->configured();

        $summaryError = false;
        try {
            $summary = $sellWindow->summary();
        } catch (\RuntimeException $exception) {
            // The ARI path still fails closed; the dashboard must stay available so
            // the owner can see and act on the configuration problem.
            report($exception);
            $summaryError = true;
            $summary = [
                'configured_until' => null,
                'effective_until' => null,
                'applied_until' => null,
            ];
        }

        $activeRoomTypeIds = Room::query()
            ->whereNotNull('room_type_id')
            ->distinct()
            ->pluck('room_type_id')
            ->values();
        $completeMappedRoomTypeIds = ChannelMapping::query()
            ->where('channel', 'channex')
            ->whereIn('room_type_id', $activeRoomTypeIds)
            ->whereNotNull('channex_room_type_id')
            ->whereNotNull('channex_rate_plan_id')
            ->pluck('room_type_id')
            ->unique()
            ->values();
        $mappingIncomplete = $activeRoomTypeIds->diff($completeMappedRoomTypeIds)->isNotEmpty();

        $lastSuccess = ChannelSyncLog::query()
            ->where('channel', 'channex')
            ->where('direction', 'push')
            ->whereIn('action', ['availability', 'rate'])
            ->where('status', 'ok')
            ->latest('created_at')
            ->first(['created_at']);
        $lastError = ChannelSyncLog::query()
            ->where('channel', 'channex')
            ->where('direction', 'push')
            ->whereIn('action', ['availability', 'rate'])
            ->where('status', 'error')
            ->latest('created_at')
            ->first(['created_at']);

        // New ARI logs carry the PMS room type id. Inspect the latest availability
        // and rate result for every fully mapped type, rather than letting success
        // for one action/type hide a failure in another.
        $scopedLogs = $completeMappedRoomTypeIds->isEmpty()
            ? collect()
            : ChannelSyncLog::query()
                ->where('channel', 'channex')
                ->where('direction', 'push')
                ->whereIn('action', ['availability', 'rate'])
                ->whereIn('room_type_id', $completeMappedRoomTypeIds)
                ->where('created_at', '>=', now()->subHours(30))
                ->orderByDesc('created_at')
                ->get(['room_type_id', 'action', 'status', 'created_at']);
        $latestByTypeAndAction = $scopedLogs->unique(
            fn (ChannelSyncLog $log) => "{$log->room_type_id}:{$log->action}",
        );
        $expectedSyncRows = $completeMappedRoomTypeIds->count() * 2;
        $syncWaiting = $expectedSyncRows > 0 && $latestByTypeAndAction->isEmpty();
        $syncPartial = $expectedSyncRows > 0
            && ! $syncWaiting
            && $latestByTypeAndAction->count() < $expectedSyncRows;
        $unresolvedError = $latestByTypeAndAction->contains(
            fn (ChannelSyncLog $log) => $log->status === 'error',
        );
        $syncStale = $latestByTypeAndAction->contains(
            fn (ChannelSyncLog $log) => $log->created_at->lt(now()->subHours(26)),
        );
        $cutoffPending = $summary['configured_until'] !== null
            && $summary['applied_until'] !== $summary['effective_until'];

        if (! $configured) {
            $status = 'not_configured';
            $label = 'Channex nuk është konfiguruar';
        } elseif ($summaryError || $mappingIncomplete || $unresolvedError || $syncPartial || $cutoffPending || $syncStale) {
            $status = 'attention';
            $label = $mappingIncomplete
                ? 'Lidhja e tipeve me Channex është e paplotë'
                : 'Sinkronizimi me Channex kërkon kontroll';
        } elseif ($syncWaiting || ($expectedSyncRows === 0 && ! $lastSuccess)) {
            $status = 'waiting';
            $label = 'Në pritje të sinkronizimit të parë';
        } else {
            $status = 'ok';
            $label = 'Sinkronizimi me Channex në rregull';
        }

        return [
            'status' => $status,
            'label' => $label,
            'last_sync_at' => $lastSuccess?->created_at?->toIso8601String(),
            'last_error_at' => $lastError?->created_at?->toIso8601String(),
            'sell_until' => $summary['effective_until'],
            'applied_until' => $summary['applied_until'],
            'mapped_room_types' => $completeMappedRoomTypeIds->count(),
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function ownerPulse(Carbon $today): array
    {
        $monthStart = $today->copy()->startOfMonth();
        $previousMonthEnd = $today->copy()->subMonthNoOverflow();
        $previousMonthStart = $previousMonthEnd->copy()->startOfMonth();

        $todayCollections = $this->collectionTotals($today, $today);
        $monthCollections = $this->collectionTotals($monthStart, $today);
        $previousMonthCollections = $this->collectionTotals($previousMonthStart, $previousMonthEnd);
        $current = $monthCollections['total'];
        $previous = $previousMonthCollections['total'];

        return [
            'collected_today' => round($todayCollections['total'], 2),
            'cash_today' => round($todayCollections['cash'], 2),
            'card_today' => round($todayCollections['card'], 2),
            'collected_month' => round($current, 2),
            'collected_month_prev' => round($previous, 2),
            'collected_month_delta' => $previous > 0
                ? (int) round(($current - $previous) / $previous * 100)
                : null,
            'top_channel' => $this->topChannel($today),
        ];
    }

    /** @return array{total: float, cash: float, card: float} */
    private function collectionTotals(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $fromTimestamp = $from->copy()->startOfDay();
        $toTimestamp = $to->copy()->endOfDay();

        $payments = Payment::query()
            ->notVoided()
            ->whereBetween('created_at', [$fromTimestamp, $toTimestamp])
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->pluck('total', 'method');

        $pos = PosOrder::query()
            ->where('status', 'completed')
            ->whereIn('payment_method', ['cash', 'card'])
            ->where(function (Builder $query) use ($fromDate, $toDate, $fromTimestamp, $toTimestamp) {
                $query->where(function (Builder $businessDate) use ($fromDate, $toDate) {
                    $businessDate->whereNotNull('business_date')
                        ->whereDate('business_date', '>=', $fromDate)
                        ->whereDate('business_date', '<=', $toDate);
                })
                    ->orWhere(function (Builder $fallback) use ($fromTimestamp, $toTimestamp) {
                        $fallback->whereNull('business_date')
                            ->whereBetween('paid_at', [$fromTimestamp, $toTimestamp]);
                    })
                    ->orWhere(function (Builder $legacy) use ($fromTimestamp, $toTimestamp) {
                        // Orders completed before paid_at/business_date were wired use updated_at,
                        // which is the immutable completion timestamp once an order is no longer open.
                        $legacy->whereNull('business_date')
                            ->whereNull('paid_at')
                            ->whereBetween('updated_at', [$fromTimestamp, $toTimestamp]);
                    });
            })
            ->select('payment_method', DB::raw('SUM(total_amount) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $paymentTotal = (float) $payments->sum();
        $posTotal = (float) $pos->sum();

        return [
            'total' => $paymentTotal + $posTotal,
            'cash' => (float) ($payments['cash'] ?? 0) + (float) ($pos['cash'] ?? 0),
            'card' => (float) ($payments['card'] ?? 0) + (float) ($pos['card'] ?? 0),
        ];
    }

    private function topChannel(Carbon $today): ?string
    {
        $rows = Reservation::query()
            ->whereBetween('check_in_date', [$today->copy()->subDays(29)->toDateString(), $today->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->select('channel', DB::raw('SUM(total_amount) as value'))
            ->groupBy('channel')
            ->get()
            ->groupBy(fn ($row) => Reservation::normalizeChannel($row->channel))
            ->map(fn ($group) => (float) $group->sum('value'))
            ->sortDesc();

        return $rows->keys()->first();
    }

    /** @return array<int, array{date: string, pct: int, rooms: int}> */
    private function occupancyForecast(Carbon $today, Collection $sellableRoomIds): array
    {
        $sellableRooms = $sellableRoomIds->count();
        $end = $today->copy()->addDays(14)->toDateString();
        $reservations = $sellableRooms
            ? Reservation::query()
                ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
                ->where('check_in_date', '<', $end)
                ->where('check_out_date', '>', $today->toDateString())
                ->whereIn('room_id', $sellableRoomIds)
                ->get(['room_id', 'check_in_date', 'check_out_date'])
            : collect();

        $forecast = [];
        for ($offset = 0; $offset < 14; $offset++) {
            $day = $today->copy()->addDays($offset);
            $sold = $reservations
                ->filter(fn (Reservation $reservation) => $day->betweenIncluded(
                    $reservation->check_in_date,
                    $reservation->check_out_date->copy()->subDay(),
                ))
                ->pluck('room_id')
                ->unique()
                ->count();
            $forecast[] = [
                'date' => $day->toDateString(),
                'pct' => $sellableRooms ? (int) round($sold / $sellableRooms * 100) : 0,
                'rooms' => $sold,
            ];
        }

        return $forecast;
    }

    /** @return Collection<int, float> */
    private function balancesFor(Collection $reservations): Collection
    {
        if ($reservations->isEmpty()) {
            return collect();
        }

        $ids = $reservations->pluck('id')->all();
        $folio = FolioItem::query()
            ->whereIn('reservation_id', $ids)
            ->select(
                'reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount', 'room') THEN amount_base ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount_base ELSE 0 END) as discounts"),
            )
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');
        $payments = Payment::query()
            ->whereIn('reservation_id', $ids)
            ->notVoided()
            ->select('reservation_id', DB::raw('SUM(amount_base) as paid'))
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');

        return $reservations->mapWithKeys(function (Reservation $reservation) use ($folio, $payments) {
            $items = $folio->get($reservation->id);
            $paid = $payments->get($reservation->id);
            $balance = (float) $reservation->total_amount_base
                + (float) ($items?->charges ?? 0)
                - (float) ($items?->discounts ?? 0)
                - (float) ($paid?->paid ?? 0);

            return [$reservation->id => round($balance, 2)];
        });
    }
}
