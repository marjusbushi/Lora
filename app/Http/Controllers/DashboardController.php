<?php

namespace App\Http\Controllers;

use App\Models\CleaningTask;
use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = today();
        $todayStr = $today->toDateString();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        // Same span last month (1st → same day) for a fair month-to-date comparison.
        $prevMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $prevMonthToDate = $today->copy()->subMonthNoOverflow()->toDateString();

        $totalRooms = Room::count();
        $occupied = Room::where('status', 'occupied')->count();

        // ---- Revenue helpers (room = arrival-based; F&B = completed POS by day) ----
        $roomRevenue = fn ($from, $to) => (float) Reservation::whereBetween('check_in_date', [$from, $to])
            ->where('status', '!=', 'cancelled')->sum('total_amount');
        $posRevenue = fn ($from, $to) => (float) PosOrder::where('status', 'completed')
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->sum('total_amount');

        $roomToday = $roomRevenue($todayStr, $todayStr);
        $posToday = $posRevenue($todayStr, $todayStr);
        $roomMonth = $roomRevenue($monthStart, $todayStr);
        $posMonth = $posRevenue($monthStart, $todayStr);
        $revPrevMonth = $roomRevenue($prevMonthStart, $prevMonthToDate) + $posRevenue($prevMonthStart, $prevMonthToDate);
        $revMonth = $roomMonth + $posMonth;

        // ---- Outstanding balance across active stays (checked_in + confirmed) ----
        $activeStays = Reservation::whereIn('status', ['checked_in', 'confirmed'])
            ->get(['id', 'total_amount']);
        [$folioMap, $payMap] = $this->folioAndPayments($activeStays->pluck('id')->all());
        $balanceOf = function ($r) use ($folioMap, $payMap) {
            $f = $folioMap[$r->id] ?? null;
            $charges = (float) ($f->charges ?? 0);
            $discounts = (float) ($f->discounts ?? 0);
            $paid = (float) ($payMap[$r->id]->paid ?? 0);
            return round((float) $r->total_amount + $charges - $discounts - $paid, 2);
        };
        $owing = $activeStays->map($balanceOf)->filter(fn ($b) => $b > 0.009);
        $outstanding = round($owing->sum(), 2);
        $owingCount = $owing->count();

        // ---- Cash & VAT today ----
        $taxRate = (float) Setting::get('financial.tax_rate', 20);
        $payByMethodToday = Payment::whereDate('created_at', $today)
            ->notVoided()
            ->select('method', DB::raw('SUM(amount) as s'))->groupBy('method')->pluck('s', 'method');
        $posByMethodToday = PosOrder::where('status', 'completed')->whereDate('created_at', $today)
            ->select('payment_method', DB::raw('SUM(total_amount) as s'))->groupBy('payment_method')->pluck('s', 'payment_method');
        $cashToday = (float) ($payByMethodToday['cash'] ?? 0) + (float) ($posByMethodToday['cash'] ?? 0);
        $cardToday = (float) ($payByMethodToday['card'] ?? 0) + (float) ($posByMethodToday['card'] ?? 0);
        $grossToday = $roomToday + $posToday;
        $vatToday = $taxRate > 0 ? round($grossToday - ($grossToday / (1 + $taxRate / 100)), 2) : 0.0;

        // ---- Operational: arrivals / departures with balance ----
        $arrivals = Reservation::whereDate('check_in_date', $today)
            ->whereIn('status', ['confirmed', 'pending'])
            ->with(['room:id,room_number', 'room.roomType:id,name', 'guest:id,first_name,last_name'])
            ->orderBy('check_in_date')->get();
        $departures = Reservation::whereDate('check_out_date', $today)
            ->where('status', 'checked_in')
            ->with(['room:id,room_number', 'room.roomType:id,name', 'guest:id,first_name,last_name'])
            ->get();

        [$adFolio, $adPay] = $this->folioAndPayments($arrivals->pluck('id')->merge($departures->pluck('id'))->all());
        $balanceWith = fn ($r) => round((float) $r->total_amount
            + (float) (($adFolio[$r->id]->charges ?? 0))
            - (float) (($adFolio[$r->id]->discounts ?? 0))
            - (float) (($adPay[$r->id]->paid ?? 0)), 2);
        $rowMap = fn ($r) => [
            'id' => $r->id,
            'guest' => trim("{$r->guest?->first_name} {$r->guest?->last_name}") ?: 'Mysafir',
            'room' => $r->room?->room_number,
            'room_type' => $r->room?->roomType?->name,
            'status' => $r->status,
            'balance' => $balanceWith($r),
        ];

        // ---- Room status board ----
        $rooms = Room::with('roomType:id,name')->orderBy('floor')->orderBy('room_number')
            ->get(['id', 'room_number', 'floor', 'status', 'room_type_id']);
        $roomStatusCounts = [
            'available' => $rooms->where('status', 'available')->count(),
            'occupied' => $rooms->where('status', 'occupied')->count(),
            'cleaning' => $rooms->where('status', 'cleaning')->count(),
            'maintenance' => $rooms->where('status', 'maintenance')->count(),
        ];

        // ---- Housekeeping queue (open tasks), RUSH = same-day arrival on that room ----
        $arrivalRoomIds = $arrivals->pluck('room.id')->filter()->unique()->all();
        $hkTasks = CleaningTask::whereIn('status', ['pending', 'in_progress'])
            ->with(['room:id,room_number', 'assignedUser:id,name'])
            ->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'room' => $t->room?->room_number,
                'type' => $t->type,
                'priority' => $t->priority,
                'status' => $t->status,
                'assigned_to' => $t->assignedUser?->name,
                'rush' => in_array($t->room_id, $arrivalRoomIds, true),
            ]);

        // ---- Open POS orders ----
        $openPos = PosOrder::where('status', 'open')
            ->orderByDesc('created_at')
            ->get(['id', 'table_number', 'total_amount', 'created_at']);

        // ---- Alerts ----
        $alerts = [];
        $overstays = Reservation::where('status', 'checked_in')->whereDate('check_out_date', '<', $today)->count();
        if ($overstays) {
            $alerts[] = ['type' => 'overstay', 'level' => 'error', 'count' => $overstays,
                'message' => "{$overstays} qëndrim(e) e kaluar afatin e daljes ende brenda"];
        }
        $noShows = Reservation::where('status', 'pending')->whereDate('check_in_date', '<', $today)->count();
        if ($noShows) {
            $alerts[] = ['type' => 'no_show', 'level' => 'warning', 'count' => $noShows,
                'message' => "{$noShows} rezervim(e) në pritje me datë hyrjeje të kaluar (mundësi no-show)"];
        }
        $staleOpenPos = $openPos->filter(fn ($o) => $o->created_at->lt($today))->count();
        if ($staleOpenPos) {
            $alerts[] = ['type' => 'stale_pos', 'level' => 'warning', 'count' => $staleOpenPos,
                'message' => "{$staleOpenPos} porosi POS e hapur nga ditët e kaluara"];
        }
        $shiftShort = (float) PosShift::where('status', 'closed')->whereDate('closed_at', $today)
            ->where('over_short', '!=', 0)->sum('over_short');
        if (abs($shiftShort) > 0.009) {
            $alerts[] = ['type' => 'cash', 'level' => 'warning', 'count' => 1,
                'message' => 'Mbyllja e turnit sot ka diferencë në arkë: ' . number_format($shiftShort, 2)];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'occupancy' => $totalRooms ? (int) round($occupied / $totalRooms * 100) : 0,
                'occupied' => $occupied,
                'total_rooms' => $totalRooms,
                'revenue_today' => round($grossToday, 2),
                'revenue_today_room' => round($roomToday, 2),
                'revenue_today_pos' => round($posToday, 2),
                'revenue_month' => round($revMonth, 2),
                'revenue_month_prev' => round($revPrevMonth, 2),
                'revenue_month_delta' => $revPrevMonth > 0 ? (int) round(($revMonth - $revPrevMonth) / $revPrevMonth * 100) : null,
                'outstanding' => $outstanding,
                'owing_count' => $owingCount,
                'cash_today' => round($cashToday, 2),
                'card_today' => round($cardToday, 2),
                'vat_today' => $vatToday,
                'in_house' => Reservation::where('status', 'checked_in')->count(),
                'arrivals' => $arrivals->count(),
                'departures' => $departures->count(),
                'open_pos' => $openPos->count(),
                'open_pos_total' => round((float) $openPos->sum('total_amount'), 2),
            ],
            'arrivals' => $arrivals->map($rowMap),
            'departures' => $departures->map($rowMap),
            'rooms' => $rooms->map(fn ($r) => [
                'room_number' => $r->room_number,
                'status' => $r->status,
                'room_type' => $r->roomType?->name,
            ]),
            'roomStatusCounts' => $roomStatusCounts,
            'housekeeping' => $hkTasks,
            'openPos' => $openPos->map(fn ($o) => [
                'id' => $o->id, 'table' => $o->table_number, 'total' => (float) $o->total_amount,
            ]),
            'alerts' => $alerts,
            'charts' => [
                'revenue14' => $this->revenue14($today),
                'occupancy14' => $this->occupancy14($today, $totalRooms),
                'channelMix' => $this->channelMix($today),
            ],
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
        ]);
    }

    /** Grouped folio (charges excl room/discount; discounts) + payments sums, keyed by reservation_id. */
    private function folioAndPayments(array $ids): array
    {
        if (empty($ids)) {
            return [collect(), collect()];
        }
        $folio = FolioItem::whereIn('reservation_id', $ids)
            ->select('reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount','room') THEN amount ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) as discounts"))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');
        $pay = Payment::whereIn('reservation_id', $ids)
            ->notVoided()
            ->select('reservation_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        return [$folio, $pay];
    }

    /** Last 14 days: room revenue (by check-in) + F&B revenue (completed POS by day). */
    private function revenue14(Carbon $today): array
    {
        $start = $today->copy()->subDays(13)->toDateString();
        $room = Reservation::whereBetween('check_in_date', [$start, $today->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->select('check_in_date', DB::raw('SUM(total_amount) as v'))
            ->groupBy('check_in_date')->pluck('v', 'check_in_date');
        $pos = PosOrder::where('status', 'completed')
            ->whereBetween('created_at', ["{$start} 00:00:00", "{$today->toDateString()} 23:59:59"])
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(total_amount) as v'))
            ->groupBy(DB::raw('DATE(created_at)'))->pluck('v', 'd');

        // Carbon date-casts can key as full datetimes; normalize to Y-m-d for lookup.
        $roomByDay = collect($room)->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => $v]);

        $out = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i)->toDateString();
            $out[] = [
                'date' => $d,
                'room' => round((float) ($roomByDay[$d] ?? 0), 2),
                'pos' => round((float) ($pos[$d] ?? 0), 2),
            ];
        }
        return $out;
    }

    /** Next 14 days: occupancy % from reservations overlapping each day. */
    private function occupancy14(Carbon $today, int $totalRooms): array
    {
        $end = $today->copy()->addDays(14)->toDateString();
        $res = Reservation::whereIn('status', ['confirmed', 'checked_in', 'pending'])
            ->where('check_in_date', '<', $end)
            ->where('check_out_date', '>', $today->toDateString())
            ->get(['room_id', 'check_in_date', 'check_out_date']);

        $out = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $today->copy()->addDays($i);
            $rooms = $res->filter(fn ($r) => $day->betweenIncluded($r->check_in_date, $r->check_out_date->copy()->subDay()))
                ->pluck('room_id')->unique()->count();
            $out[] = [
                'date' => $day->toDateString(),
                'pct' => $totalRooms ? (int) round($rooms / $totalRooms * 100) : 0,
                'rooms' => $rooms,
            ];
        }
        return $out;
    }

    /** Last 30 days: revenue by channel (for the channel-mix bars). */
    private function channelMix(Carbon $today): array
    {
        $start = $today->copy()->subDays(29)->toDateString();
        return Reservation::whereBetween('check_in_date', [$start, $today->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->select('channel', DB::raw('SUM(total_amount) as v'), DB::raw('COUNT(*) as c'))
            ->groupBy('channel')->get()
            ->map(fn ($r) => [
                'channel' => $r->channel ?: 'manual',
                'revenue' => round((float) $r->v, 2),
                'count' => (int) $r->c,
            ])
            ->sortByDesc('revenue')->values()->all();
    }
}
