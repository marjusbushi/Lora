<?php

namespace App\Http\Controllers;

use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    /** Reports hub — the catalog of every report. */
    public function index(): Response
    {
        return Inertia::render('Reports/Index', [
            'currency' => $this->currency(),
        ]);
    }

    /** Executive summary: revenue (room+F&B), occupancy, ADR, RevPAR, VAT, commission. */
    public function executive(Request $request): Response
    {
        [$from, $to, $days] = $this->range($request);

        $reservations = Reservation::whereBetween('check_in_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'check_in_date', 'check_out_date', 'status', 'total_amount', 'commission_amount']);

        $roomRevenue = (float) $reservations->sum('total_amount');
        $nightsSold = (int) $reservations->sum(fn ($r) => $r->nights);
        $commission = (float) $reservations->sum('commission_amount');

        $posRevenue = (float) PosOrder::where('status', 'completed')
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->sum('total_amount');
        $posCount = PosOrder::where('status', 'completed')
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->count();

        $totalRevenue = $roomRevenue + $posRevenue;
        $roomsCount = Room::count();
        $availableRoomNights = $roomsCount * $days;
        $taxRate = (float) Setting::get('financial.tax_rate', 20);
        $vat = $taxRate > 0 ? round($totalRevenue - ($totalRevenue / (1 + $taxRate / 100)), 2) : 0.0;

        $byStatus = Reservation::whereBetween('check_in_date', [$from, $to])
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as revenue'))
            ->groupBy('status')->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count, 'revenue' => (float) $r->revenue]);

        return Inertia::render('Reports/Executive', [
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'room_revenue' => round($roomRevenue, 2),
                'pos_revenue' => round($posRevenue, 2),
                'total_revenue' => round($totalRevenue, 2),
                'commission' => round($commission, 2),
                'net_room_revenue' => round($roomRevenue - $commission, 2),
                'vat' => $vat,
                'net_revenue' => round($totalRevenue - $vat, 2),
                'reservation_count' => $reservations->count(),
                'pos_count' => $posCount,
                'nights_sold' => $nightsSold,
                'rooms_count' => $roomsCount,
                'days' => $days,
                'occupancy' => $availableRoomNights ? round($nightsSold / $availableRoomNights * 100, 1) : 0,
                'adr' => $nightsSold ? round($roomRevenue / $nightsSold, 2) : 0,
                'revpar' => $availableRoomNights ? round($roomRevenue / $availableRoomNights, 2) : 0,
            ],
            'byStatus' => $byStatus,
            'currency' => $this->currency(),
        ]);
    }

    /** Production by channel: bookings, revenue, commission, net, nights. */
    public function channels(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $rows = Reservation::whereBetween('check_in_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->get(['channel', 'total_amount', 'commission_amount', 'check_in_date', 'check_out_date'])
            ->groupBy(fn ($r) => $r->channel ?: 'manual')
            ->map(function ($group, $channel) {
                $revenue = (float) $group->sum('total_amount');
                $commission = (float) $group->sum('commission_amount');
                return [
                    'channel' => $channel,
                    'count' => $group->count(),
                    'nights' => (int) $group->sum(fn ($r) => $r->nights),
                    'revenue' => round($revenue, 2),
                    'commission' => round($commission, 2),
                    'net' => round($revenue - $commission, 2),
                ];
            })
            ->sortByDesc('revenue')->values();

        return Inertia::render('Reports/Channels', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'totals' => [
                'count' => (int) $rows->sum('count'),
                'nights' => (int) $rows->sum('nights'),
                'revenue' => round((float) $rows->sum('revenue'), 2),
                'commission' => round((float) $rows->sum('commission'), 2),
                'net' => round((float) $rows->sum('net'), 2),
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** Outstanding balances (debtors): every non-cancelled stay that still owes money. */
    public function outstanding(): Response
    {
        $stays = Reservation::whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->with(['room:id,room_number', 'guest:id,first_name,last_name,phone'])
            ->get(['id', 'room_id', 'guest_id', 'status', 'check_in_date', 'check_out_date', 'total_amount']);

        $ids = $stays->pluck('id')->all();
        $folio = FolioItem::whereIn('reservation_id', $ids)
            ->select('reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount','room') THEN amount ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) as discounts"))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');
        $pay = Payment::whereIn('reservation_id', $ids)
            ->select('reservation_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        $rows = $stays->map(function ($r) use ($folio, $pay) {
            $gross = round((float) $r->total_amount + (float) ($folio[$r->id]->charges ?? 0) - (float) ($folio[$r->id]->discounts ?? 0), 2);
            $paid = (float) ($pay[$r->id]->paid ?? 0);
            return [
                'id' => $r->id,
                'guest' => trim("{$r->guest?->first_name} {$r->guest?->last_name}") ?: 'Mysafir',
                'phone' => $r->guest?->phone,
                'room' => $r->room?->room_number,
                'status' => $r->status,
                'check_in' => $r->check_in_date?->toDateString(),
                'check_out' => $r->check_out_date?->toDateString(),
                'gross' => $gross,
                'paid' => round($paid, 2),
                'balance' => round($gross - $paid, 2),
            ];
        })->filter(fn ($r) => $r['balance'] > 0.009)->sortByDesc('balance')->values();

        return Inertia::render('Reports/Outstanding', [
            'rows' => $rows,
            'total' => round((float) $rows->sum('balance'), 2),
            'currency' => $this->currency(),
        ]);
    }

    /** Z-Report: closed cash-drawer shifts per staff/day with over/short. */
    public function shifts(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $shifts = PosShift::with('user:id,name')
            ->where('status', 'closed')
            ->whereBetween('closed_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->orderByDesc('closed_at')->get();

        return Inertia::render('Reports/Shifts', [
            'filters' => ['from' => $from, 'to' => $to],
            'shifts' => $shifts->map(fn ($s) => [
                'id' => $s->id,
                'user' => $s->user?->name,
                'opened_at' => $s->opened_at?->format('d/m H:i'),
                'closed_at' => $s->closed_at?->format('d/m H:i'),
                'opening_float' => (float) $s->opening_float,
                'cash_sales' => (float) $s->cash_sales,
                'card_sales' => (float) $s->card_sales,
                'room_charge_sales' => (float) $s->room_charge_sales,
                'total_sales' => (float) $s->total_sales,
                'expected_cash' => (float) $s->expected_cash,
                'counted_cash' => (float) $s->counted_cash,
                'over_short' => (float) $s->over_short,
            ]),
            'totals' => [
                'cash' => round((float) $shifts->sum('cash_sales'), 2),
                'card' => round((float) $shifts->sum('card_sales'), 2),
                'room_charge' => round((float) $shifts->sum('room_charge_sales'), 2),
                'total' => round((float) $shifts->sum('total_sales'), 2),
                'over_short' => round((float) $shifts->sum('over_short'), 2),
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** from/to (default = current month) + inclusive day count. */
    private function range(Request $request): array
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->endOfMonth()->toDateString());
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        return [$from, $to, max(1, (int) $days)];
    }

    private function currency(): string
    {
        return Setting::get('financial.default_currency_symbol', '€');
    }
}
