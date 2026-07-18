<?php

namespace App\Http\Controllers;

use App\Models\CleaningTask;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\BaseCurrency;
use App\Services\Reporting\BookingBehaviorService;
use App\Services\Reporting\BudgetTargetService;
use App\Services\Reporting\CancellationRiskService;
use App\Services\Reporting\ChannelPerformanceService;
use App\Services\Reporting\FiscalVatReportService;
use App\Services\Reporting\HotelKpiService;
use App\Services\Reporting\OutstandingBalanceService;
use App\Services\Reporting\PaymentReconciliationService;
use App\Services\Reporting\PickupPaceService;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\RoomTypePerformanceService;
use App\Services\Reporting\StayRevenueAllocator;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    public function executive(
        Request $request,
        HotelKpiService $hotelKpis,
        BudgetTargetService $budgetTargets,
        OutstandingBalanceService $outstandingBalances,
        StayRevenueAllocator $revenueAllocator,
    ): Response {
        [$from, $to] = $this->range($request);
        $period = new ReportingPeriod($from, $to);
        $analytics = $hotelKpis->withComparisons($period);
        $budget = $budgetTargets->forPeriod($period);
        $outstanding = $outstandingBalances->summary();
        $forecastPeriod = new ReportingPeriod(today()->toDateString(), today()->addDays(29)->toDateString());
        $forecast = $hotelKpis->summary($forecastPeriod);

        $channelRows = Reservation::query()
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'channel', 'check_in_date', 'check_out_date', 'total_amount', 'commission_amount'])
            ->groupBy(fn (Reservation $reservation) => Reservation::normalizeChannel($reservation->channel))
            ->map(function ($reservations, string $channel) use ($period, $revenueAllocator) {
                $revenue = 0.0;
                $commission = 0.0;
                $nights = 0;

                foreach ($reservations as $reservation) {
                    $allocatedRevenue = $revenueAllocator->allocate(
                        $reservation->check_in_date,
                        $reservation->check_out_date,
                        $reservation->total_amount,
                        $period,
                    );
                    $revenue += array_sum($allocatedRevenue);
                    $nights += count($allocatedRevenue);
                    $commission += array_sum($revenueAllocator->allocate(
                        $reservation->check_in_date,
                        $reservation->check_out_date,
                        $reservation->commission_amount ?? 0,
                        $period,
                    ));
                }

                return [
                    'channel' => $channel,
                    'bookings' => $reservations->count(),
                    'nights' => $nights,
                    'revenue' => round($revenue, 2),
                    'commission' => round($commission, 2),
                    'net' => round($revenue - $commission, 2),
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        $peakForecast = collect($forecast['daily'])
            ->map(fn (array $day, string $date) => [
                'date' => $date,
                'occupancy' => $day['sellable_room_nights'] > 0
                    ? round($day['occupied_room_nights'] / $day['sellable_room_nights'] * 100, 1)
                    : 0,
            ])
            ->sortByDesc('occupancy')
            ->first();

        $alerts = collect();
        if ($budget['revenue_target'] && $analytics['current']['kpis']['total_revenue'] < $budget['revenue_target']) {
            $alerts->push([
                'kind' => 'budget',
                'value' => round($budget['revenue_target'] - $analytics['current']['kpis']['total_revenue'], 2),
            ]);
        }
        if ($outstanding['total'] > 0) {
            $alerts->push(['kind' => 'outstanding', 'value' => $outstanding['total'], 'count' => $outstanding['count']]);
        }
        if (($peakForecast['occupancy'] ?? 0) >= 85) {
            $alerts->push(['kind' => 'demand', 'value' => $peakForecast['occupancy'], 'date' => $peakForecast['date']]);
        }

        return Inertia::render('Reports/Executive', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $analytics,
            'budget' => $budget,
            'forecast' => $forecast,
            'outstanding' => $outstanding,
            'channels' => $channelRows,
            'alerts' => $alerts,
            'currency' => $this->currency(),
        ]);
    }

    /** Production by channel: bookings, revenue, commission, net, nights. */
    public function channels(Request $request, ChannelPerformanceService $channelPerformance): Response
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        [$from, $to] = $this->range($request);
        $period = new ReportingPeriod($from, $to);

        return Inertia::render('Reports/Channels', [
            'filters' => $period->toArray(),
            'analytics' => $channelPerformance->withComparisons($period),
            'currency' => $this->currency(),
        ]);
    }

    /** Outstanding balances (debtors): every non-cancelled stay that still owes money. */
    public function outstanding(Request $request, OutstandingBalanceService $outstandingBalances): Response
    {
        $analytics = $outstandingBalances->analytics();

        return Inertia::render('Reports/Outstanding', [
            'analytics' => $analytics,
            // Preserve the original payload while report consumers migrate to analytics.
            'rows' => $analytics['rows'],
            'total' => $analytics['summary']['total'],
            'canViewReservations' => (bool) $request->user()?->can('view_reservations'),
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

    /** Guest directory / CRM: per-guest stays, nights, spend and visit history. */
    public function guests(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $guests = Guest::with(['reservations' => function ($q) {
            $q->where('status', '!=', 'cancelled')
                ->select('id', 'guest_id', 'check_in_date', 'check_out_date', 'total_amount');
        }])->get(['id', 'first_name', 'last_name', 'email', 'phone', 'nationality']);

        $rows = $guests->map(function ($g) {
            $res = $g->reservations;
            $stays = $res->count();
            $nights = (int) $res->sum(fn ($r) => $r->nights);
            $totalSpent = (float) $res->sum('total_amount');
            $checkIns = $res->pluck('check_in_date')->filter();

            return [
                'id' => $g->id,
                'guest' => trim("{$g->first_name} {$g->last_name}") ?: 'Mysafir',
                'email' => $g->email,
                'phone' => $g->phone,
                'nationality' => $g->nationality ?: '—',
                'stays' => $stays,
                'nights' => $nights,
                'total_spent' => round($totalSpent, 2),
                'last_visit' => $checkIns->isNotEmpty() ? $checkIns->max()?->toDateString() : null,
                'first_seen' => $checkIns->isNotEmpty() ? $checkIns->min()?->toDateString() : null,
            ];
        })
            ->filter(fn ($r) => $r['stays'] > 0)
            ->sortByDesc('total_spent')
            ->values();

        return Inertia::render('Reports/Guests', [
            'rows' => $rows,
            'summary' => [
                'total_guests' => $rows->count(),
                'repeat_guests' => $rows->filter(fn ($r) => $r['stays'] >= 2)->count(),
                'total_nights' => (int) $rows->sum('nights'),
                'total_revenue' => round((float) $rows->sum('total_spent'), 2),
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** POS sales (F&B): revenue by menu category + top items for a date range. */
    public function posSales(Request $request): Response
    {
        [$from, $to, $days] = $this->range($request);

        // Operational date is the hotel business date; paid_at is the legacy fallback.
        $orderIds = $this->posBusinessRange(PosOrder::where('status', 'completed')->whereNull('refunded_at'), $from, $to)
            ->pluck('id');

        // (A) Sales by category: order_items -> menu_items -> menu_categories.
        $byCategory = DB::table('pos_order_items as oi')
            ->join('pos_orders as po', 'po.id', '=', 'oi.pos_order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->leftJoin('menu_categories as mc', 'mc.id', '=', 'mi.menu_category_id')
            ->whereIn('oi.pos_order_id', $orderIds)
            ->where('oi.tenant_id', app(TenantContext::class)->id())
            ->groupBy('mc.name')
            ->select(
                DB::raw("COALESCE(mc.name, 'Pa kategori') as category"),
                DB::raw('SUM(oi.quantity) as qty'),
                DB::raw('SUM(oi.total_price * CASE WHEN po.subtotal_amount > 0 THEN 1.0 * po.total_amount / po.subtotal_amount ELSE 1 END) as revenue')
            )
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'qty' => (int) $r->qty,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        // (B) Top 15 items by revenue.
        $topItems = DB::table('pos_order_items as oi')
            ->join('pos_orders as po', 'po.id', '=', 'oi.pos_order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->leftJoin('menu_categories as mc', 'mc.id', '=', 'mi.menu_category_id')
            ->whereIn('oi.pos_order_id', $orderIds)
            ->where('oi.tenant_id', app(TenantContext::class)->id())
            ->groupBy('mi.name', 'mc.name')
            ->select(
                'mi.name as item',
                DB::raw("COALESCE(mc.name, 'Pa kategori') as category"),
                DB::raw('SUM(oi.quantity) as qty'),
                DB::raw('SUM(oi.total_price * CASE WHEN po.subtotal_amount > 0 THEN 1.0 * po.total_amount / po.subtotal_amount ELSE 1 END) as revenue')
            )
            ->orderByDesc('revenue')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'item' => $r->item,
                'category' => $r->category,
                'qty' => (int) $r->qty,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        // (C) Summary KPIs.
        $orderCount = $orderIds->count();
        $totalRevenue = (float) PosOrder::whereIn('id', $orderIds)->sum('total_amount');
        $avgTicket = $orderCount ? $totalRevenue / $orderCount : 0.0;

        return Inertia::render('Reports/PosSales', [
            'filters' => ['from' => $from, 'to' => $to],
            'byCategory' => $byCategory,
            'topItems' => $topItems,
            'summary' => [
                'order_count' => $orderCount,
                'total_revenue' => round($totalRevenue, 2),
                'avg_ticket' => round($avgTicket, 2),
                'days' => $days,
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** Arrivals manifest: every booked arrival in range with guest, room, pax, channel and balance owed. */
    public function arrivalsManifest(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $arrivals = Reservation::whereBetween('check_in_date', [$from, $to])
            ->whereIn('status', ['confirmed', 'checked_in', 'pending'])
            ->with(['room:id,room_number,room_type_id', 'room.roomType:id,name', 'guest:id,first_name,last_name,phone'])
            ->get([
                'id', 'room_id', 'guest_id', 'status',
                'check_in_date', 'check_out_date', 'total_amount',
                'adults', 'children', 'channel', 'notes',
            ]);

        $ids = $arrivals->pluck('id')->all();

        $folio = FolioItem::whereIn('reservation_id', $ids)
            ->select('reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount','room') THEN amount ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) as discounts"))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        $pay = Payment::whereIn('reservation_id', $ids)
            ->notVoided()
            ->select('reservation_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        $rows = $arrivals->map(function ($r) use ($folio, $pay) {
            $gross = round((float) $r->total_amount
                + (float) ($folio[$r->id]->charges ?? 0)
                - (float) ($folio[$r->id]->discounts ?? 0), 2);
            $paid = (float) ($pay[$r->id]->paid ?? 0);

            return [
                'id' => $r->id,
                'check_in' => $r->check_in_date?->toDateString(),
                'check_out' => $r->check_out_date?->toDateString(),
                'guest' => trim("{$r->guest?->first_name} {$r->guest?->last_name}") ?: 'Mysafir',
                'phone' => $r->guest?->phone,
                'room' => $r->room?->room_number,
                'room_type' => $r->room?->roomType?->name,
                'status' => $r->status,
                'nights' => (int) $r->nights,
                'pax' => (int) $r->adults + (int) $r->children,
                'adults' => (int) $r->adults,
                'children' => (int) $r->children,
                'channel' => Reservation::normalizeChannel($r->channel),
                'balance' => round($gross - $paid, 2),
                'notes' => $r->notes,
            ];
        })
            ->sortBy([
                ['check_in', 'asc'],
                ['room', 'asc'],
            ])->values();

        return Inertia::render('Reports/ArrivalsManifest', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'totals' => [
                'count' => $rows->count(),
                'nights' => (int) $rows->sum('nights'),
                'pax' => (int) $rows->sum('pax'),
                'revenue' => round((float) $arrivals->sum('total_amount'), 2),
                'balance' => round((float) $rows->sum('balance'), 2),
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** Departures manifest: stays checking out in range, with balance owed + open POS orders. */
    public function departuresManifest(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $stays = Reservation::whereBetween('check_out_date', [$from, $to])
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->with(['room:id,room_number', 'guest:id,first_name,last_name,phone'])
            ->get(['id', 'room_id', 'guest_id', 'status', 'check_in_date', 'check_out_date', 'total_amount']);

        $ids = $stays->pluck('id')->all();

        $folio = FolioItem::whereIn('reservation_id', $ids)
            ->select('reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount','room') THEN amount ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) as discounts"))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        $pay = Payment::whereIn('reservation_id', $ids)
            ->notVoided()
            ->select('reservation_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        $openPos = PosOrder::whereIn('reservation_id', $ids)
            ->where('status', 'open')
            ->select('reservation_id', DB::raw('count(*) as open_count'))
            ->groupBy('reservation_id')->get()->keyBy('reservation_id');

        $rows = $stays->map(function ($r) use ($folio, $pay, $openPos) {
            $gross = round((float) $r->total_amount + (float) ($folio[$r->id]->charges ?? 0) - (float) ($folio[$r->id]->discounts ?? 0), 2);
            $paid = (float) ($pay[$r->id]->paid ?? 0);

            return [
                'id' => $r->id,
                'check_out' => $r->check_out_date?->toDateString(),
                'guest' => trim("{$r->guest?->first_name} {$r->guest?->last_name}") ?: 'Mysafir',
                'phone' => $r->guest?->phone,
                'room' => $r->room?->room_number,
                'status' => $r->status,
                'balance' => round($gross - $paid, 2),
                'open_pos_count' => (int) ($openPos[$r->id]->open_count ?? 0),
            ];
        })->sortBy('check_out')->values();

        return Inertia::render('Reports/DeparturesManifest', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'totals' => [
                'count' => $rows->count(),
                'outstanding' => round((float) $rows->sum('balance'), 2),
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** Tempo & Pickup: real on-the-books movement against nightly historical snapshots. */
    public function pace(Request $request, PickupPaceService $pickupPace): Response
    {
        $today = Carbon::today();
        $latest = $today->copy()->addDays(364);
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$latest->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'after_or_equal:from', 'before_or_equal:'.$latest->toDateString()],
        ]);

        $from = Carbon::parse($request->input('from', $today->toDateString()));
        $to = Carbon::parse($request->input('to', $today->copy()->addDays(29)->toDateString()));
        if ($to->lt($from)) {
            $to = $from->copy();
        }
        $period = new ReportingPeriod($from->toDateString(), $to->toDateString());

        return Inertia::render('Reports/Pace', [
            'filters' => $period->toArray(),
            'analytics' => $pickupPace->summary($period),
            'currency' => $this->currency(),
        ]);
    }

    /** Anulime & No-Show: cancellation rate + value, plus unresolved past-arrival candidates. */
    public function cancellations(Request $request, CancellationRiskService $cancellationRisk): Response
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if ($request->filled('from') && Carbon::parse($request->input('from'))->diffInDays(Carbon::parse($value)) > 366) {
                        $fail(app()->getLocale() === 'sq'
                            ? 'Periudha e raportit nuk mund të kalojë 367 ditë.'
                            : 'The report period cannot exceed 367 days.');
                    }
                },
            ],
        ]);

        [$from, $to] = $this->range($request);
        $period = new ReportingPeriod($from, $to);
        $analytics = $cancellationRisk->withComparisons($period);
        $current = $analytics['current'];

        // Preserve the original report contract used by dashboard action links.
        // In that contract, "no show" means unresolved past-arrival candidates.
        $legacySummary = [
            'cancelled_count' => $current['summary']['cancelled_count'],
            'cancelled_value' => $current['summary']['cancelled_value'],
            'total_count' => $current['summary']['total_count'],
            'cancellation_rate' => $current['summary']['cancellation_rate'],
            'no_show_count' => $current['summary']['at_risk_count'],
            'no_show_value' => $current['summary']['at_risk_value'],
        ];

        return Inertia::render('Reports/Cancellations', [
            'filters' => $period->toArray(),
            'analytics' => $analytics,
            'summary' => $legacySummary,
            'cancelled' => collect($current['losses'])->where('type', 'cancelled')->values(),
            'noShows' => $current['at_risk'],
            'canViewReservations' => (bool) $request->user()?->can('view_reservations'),
            'currency' => $this->currency(),
        ]);
    }

    /** Arkëtime & Cash: money actually COLLECTED in range (payments + completed POS), by method + per day. */
    public function payments(Request $request, PaymentReconciliationService $paymentReconciliation): Response
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from',
            ],
        ]);

        [$from, $to] = $this->range($request);
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages([
                'to' => app()->getLocale() === 'sq'
                    ? 'Periudha e raportit nuk mund të kalojë 367 ditë.'
                    : 'The report period cannot exceed 367 days.',
            ]);
        }
        $analytics = $paymentReconciliation->summary(new ReportingPeriod($from, $to));
        $summary = $analytics['summary'];
        $rows = collect($analytics['daily'])->map(fn (array $day) => [
            'date' => $day['date'],
            'payments_cash' => $day['pms_cash'],
            'payments_card' => $day['pms_card'],
            'pos_total' => round($day['pos_cash'] + $day['pos_card'] + $day['room_charge'], 2),
            'total' => round($day['total'] + $day['room_charge'], 2),
        ]);
        $byMethod = [
            ['method' => 'cash', 'label' => 'Kesh', 'amount' => $summary['cash']],
            ['method' => 'card', 'label' => 'Kartë', 'amount' => $summary['card']],
            ['method' => 'room_charge', 'label' => 'Faturë dhome', 'amount' => $summary['room_charge']],
        ];

        return Inertia::render('Reports/Payments', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $analytics,
            'rows' => $rows,
            'byMethod' => $byMethod,
            'totals' => [
                'payments_cash' => round((float) $rows->sum('payments_cash'), 2),
                'payments_card' => round((float) $rows->sum('payments_card'), 2),
                'pos_total' => round((float) $rows->sum('pos_total'), 2),
                'cash' => $summary['cash'],
                'card' => $summary['card'],
                'room_charge' => $summary['room_charge'],
                'total' => round($summary['collected'] + $summary['room_charge'], 2),
            ],
            'canViewReservations' => (bool) $request->user()?->can('view_reservations'),
            'canViewPos' => (bool) $request->user()?->can('view_pos_orders'),
            'currency' => $this->currency(),
        ]);
    }

    /** TVSH & Faturat Fiskale: fiscal coverage, provider failures and VAT from actual fiscal documents. */
    public function vat(Request $request, FiscalVatReportService $fiscalVatReport): Response
    {
        [$from, $to] = $this->range($request);
        $analytics = $fiscalVatReport->summary(new ReportingPeriod($from, $to));

        return Inertia::render('Reports/Vat', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $analytics,
            'summary' => $analytics['summary'],
            'rows' => $analytics['documents'],
            'canViewReservations' => (bool) $request->user()?->can('view_reservations'),
            'canViewPos' => (bool) $request->user()?->can('view_pos_orders'),
            'currency' => $this->currency(),
        ]);
    }

    /** ADR / RevPAR / Mbushja: per room type for a date range — nights, revenue, ADR, occupancy %, RevPAR. */
    public function performance(
        Request $request,
        RoomTypePerformanceService $performance,
        BudgetTargetService $budgetTargets,
    ): Response {
        [$from, $to] = $this->range($request);
        $period = new ReportingPeriod($from, $to);

        return Inertia::render('Reports/Performance', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $performance->withComparisons($period),
            'budget' => $budgetTargets->forPeriod($period),
            'currency' => $this->currency(),
        ]);
    }

    /** Mysafirë Kthyes & Top: lifetime per-guest stays/nights/spend (non-cancelled), top spenders + repeat flag. */
    public function repeatGuests(Request $request): Response
    {
        $guests = Guest::with(['reservations' => function ($q) {
            $q->where('status', '!=', 'cancelled')
                ->select('id', 'guest_id', 'check_in_date', 'check_out_date', 'total_amount');
        }])->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $all = $guests->map(function ($g) {
            $res = $g->reservations;
            $stays = $res->count();
            $checkIns = $res->pluck('check_in_date')->filter();

            return [
                'id' => $g->id,
                'guest' => trim("{$g->first_name} {$g->last_name}") ?: 'Mysafir',
                'email' => $g->email,
                'phone' => $g->phone,
                'stays' => $stays,
                'nights' => (int) $res->sum(fn ($r) => $r->nights),
                'total_spent' => round((float) $res->sum('total_amount'), 2),
                'last_visit' => $checkIns->isNotEmpty() ? $checkIns->max()?->toDateString() : null,
                'is_repeat' => $stays >= 2,
            ];
        })->filter(fn ($r) => $r['stays'] >= 1)->values();

        $totalGuests = $all->count();
        $repeatGuests = $all->filter(fn ($r) => $r['is_repeat'])->count();

        // Table: top 50 by lifetime spend (repeat badge shown inline).
        $rows = $all->sortByDesc('total_spent')->take(50)->values();

        return Inertia::render('Reports/RepeatGuests', [
            'rows' => $rows,
            'summary' => [
                'total_guests' => $totalGuests,
                'repeat_guests' => $repeatGuests,
                'repeat_rate' => $totalGuests ? round($repeatGuests / $totalGuests * 100, 1) : 0,
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** Guest mix by nationality: distinct guests, stays, nights, revenue, ALOS. */
    public function nationality(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $reservations = Reservation::whereBetween('check_in_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->whereHas('guest')
            ->with('guest:id,nationality')
            ->get(['id', 'guest_id', 'check_in_date', 'check_out_date', 'total_amount']);

        $rows = $reservations
            ->groupBy(fn ($r) => ($r->guest && filled($r->guest->nationality)) ? $r->guest->nationality : 'E panjohur')
            ->map(function ($group, $nationality) {
                $stays = $group->count();
                $nights = (int) $group->sum(fn ($r) => $r->nights);
                $revenue = (float) $group->sum('total_amount');
                $guests = $group->pluck('guest_id')->unique()->count();

                return [
                    'nationality' => $nationality,
                    'guests' => $guests,
                    'stays' => $stays,
                    'nights' => $nights,
                    'revenue' => round($revenue, 2),
                    'alos' => $stays > 0 ? round($nights / $stays, 1) : 0,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        $totalStays = (int) $rows->sum('stays');
        $totalNights = (int) $rows->sum('nights');

        return Inertia::render('Reports/Nationality', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'totals' => [
                'guests' => (int) $rows->sum('guests'),
                'stays' => $totalStays,
                'nights' => $totalNights,
                'revenue' => round((float) $rows->sum('revenue'), 2),
                'alos' => $totalStays > 0 ? round($totalNights / $totalStays, 1) : 0,
            ],
            'currency' => $this->currency(),
        ]);
    }

    public function bookingBehavior(Request $request, BookingBehaviorService $bookingBehavior): Response
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        [$from, $to] = $this->range($request);
        $period = new ReportingPeriod($from, $to);

        return Inertia::render('Reports/BookingBehavior', [
            'filters' => $period->toArray(),
            'analytics' => $bookingBehavior->withComparisons($period),
        ]);
    }

    /**
     * POS sales by hour-of-day and weekday over a date range.
     * Cross-DB safe: we fetch completed POS orders then bucket hour/weekday in PHP via Carbon
     * (no MySQL-only HOUR()/strftime). Hour = 0..23, weekday = ISO 1=Mon..7=Sun.
     */
    public function posHourly(Request $request): Response
    {
        [$from, $to, $days] = $this->range($request);

        $orders = $this->posBusinessRange(PosOrder::where('status', 'completed')->whereNull('refunded_at'), $from, $to)
            ->get(['id', 'total_amount', 'paid_at', 'created_at']);

        // 24 hour buckets.
        $byHour = [];
        for ($h = 0; $h < 24; $h++) {
            $byHour[$h] = ['hour' => $h, 'count' => 0, 'revenue' => 0.0];
        }

        // 7 weekday buckets (ISO 1=Mon .. 7=Sun).
        $weekdayLabels = [1 => 'Hën', 2 => 'Mar', 3 => 'Mër', 4 => 'Enj', 5 => 'Pre', 6 => 'Sht', 7 => 'Die'];
        $byWeekday = [];
        foreach ($weekdayLabels as $iso => $label) {
            $byWeekday[$iso] = ['weekday' => $label, 'count' => 0, 'revenue' => 0.0];
        }

        $totalRevenue = 0.0;
        $orderCount = 0;

        foreach ($orders as $o) {
            $when = Carbon::parse($o->paid_at ?? $o->created_at);
            $h = (int) $when->hour;             // 0..23
            $iso = (int) $when->dayOfWeekIso;   // 1..7
            $amount = (float) ($o->total_amount ?? 0);

            $byHour[$h]['count']++;
            $byHour[$h]['revenue'] += $amount;

            $byWeekday[$iso]['count']++;
            $byWeekday[$iso]['revenue'] += $amount;

            $totalRevenue += $amount;
            $orderCount++;
        }

        return Inertia::render('Reports/PosHourly', [
            'filters' => ['from' => $from, 'to' => $to],
            'byHour' => array_values($byHour),
            'byWeekday' => array_values($byWeekday),
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'order_count' => $orderCount,
                'days' => $days,
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** POS payment mix: completed POS orders grouped by payment method (cash, card, room_charge). */
    public function posPaymentMix(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $payments = PosOrderPayment::whereBetween('paid_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->get(['pos_order_id', 'method', 'direction', 'amount'])
            ->map(fn ($payment) => [
                'pos_order_id' => $payment->pos_order_id,
                'method' => $payment->method,
                'direction' => $payment->direction,
                'amount' => (float) $payment->amount,
            ]);

        $legacyPayments = $this->posBusinessRange(
            PosOrder::where('status', 'completed')
                ->whereDoesntHave('payments', fn ($query) => $query->where('direction', 'in')),
            $from,
            $to,
        )->get(['id', 'payment_method', 'total_amount'])
            ->map(fn ($order) => [
                'pos_order_id' => $order->id,
                'method' => $order->payment_method ?: '?',
                'direction' => 'in',
                'amount' => (float) $order->total_amount,
            ]);

        $payments = $payments->concat($legacyPayments);

        $labels = [
            'cash' => 'Kesh',
            'card' => 'Kartë',
            'room_charge' => 'Në dhomë (folio)',
            '?' => 'E papërcaktuar',
        ];

        $grouped = $payments->groupBy('method')
            ->map(fn ($group, $method) => [
                'method' => $method,
                'count' => $group->where('direction', 'in')->pluck('pos_order_id')->unique()->count(),
                'total' => round((float) $group->sum(fn ($payment) => $payment['direction'] === 'in' ? $payment['amount'] : -$payment['amount']), 2),
            ]);

        $grandTotal = round((float) $payments->sum(fn ($payment) => $payment['direction'] === 'in' ? $payment['amount'] : -$payment['amount']), 2);
        $orderCount = $payments->where('direction', 'in')->pluck('pos_order_id')->unique()->count();

        // Stable ordering: cash, card, room_charge, then any leftover (e.g. '?')
        $order = ['cash', 'card', 'room_charge', '?'];
        $rows = collect($order)
            ->filter(fn ($m) => $grouped->has($m))
            ->map(fn ($m) => $grouped->get($m))
            ->merge($grouped->reject(fn ($g, $m) => in_array($m, $order, true))->values())
            ->map(fn ($r) => [
                'method' => $r['method'],
                'label' => $labels[$r['method']] ?? $r['method'],
                'count' => $r['count'],
                'total' => $r['total'],
                'pct' => $grandTotal > 0 ? round($r['total'] / $grandTotal * 100, 1) : 0.0,
            ])
            ->values();

        return Inertia::render('Reports/PosPaymentMix', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'summary' => [
                'grand_total' => $grandTotal,
                'order_count' => $orderCount,
            ],
            'currency' => $this->currency(),
        ]);
    }

    public function posVoids(Request $request): Response
    {
        [$from, $to, $days] = $this->range($request);

        $orders = PosOrder::with('createdBy')
            ->where('status', 'cancelled')
            ->where(function (Builder $query) use ($from, $to) {
                $query->whereBetween('cancelled_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
                    ->orWhere(function (Builder $legacy) use ($from, $to) {
                        $legacy->whereNull('cancelled_at')
                            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                    });
            })
            ->orderByRaw('COALESCE(cancelled_at, created_at) DESC')
            ->get();

        $rows = $orders->map(function ($o) {
            return [
                'id' => $o->id,
                'table_number' => $o->table_number,
                'total_amount' => (float) ($o->total_amount ?? 0),
                'created_at' => Carbon::parse($o->cancelled_at ?? $o->created_at)->format('d/m H:i'),
                'reason' => $o->cancellation_reason,
                'created_by' => $o->createdBy->name ?? '—',
            ];
        })->values();

        $summary = [
            'count' => $rows->count(),
            'total' => round($orders->sum('total_amount'), 2),
        ];

        return Inertia::render('Reports/PosVoids', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'summary' => $summary,
            'currency' => $this->currency(),
        ]);
    }

    /** Room status snapshot: all rooms with their type, grouped counts per status (point-in-time, no date range). */
    public function roomStatus(Request $request): Response
    {
        $rooms = Room::with('roomType')
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get(['id', 'room_type_id', 'room_number', 'floor', 'status']);

        $rows = $rooms->map(fn ($r) => [
            'id' => $r->id,
            'room_number' => $r->room_number,
            'floor' => $r->floor,
            'room_type' => $r->roomType?->name ?? '—',
            'status' => $r->status,
        ])->values();

        $statuses = ['available', 'occupied', 'cleaning', 'maintenance'];
        $counts = [];
        foreach ($statuses as $s) {
            $counts[$s] = (int) $rooms->where('status', $s)->count();
        }
        $counts['total'] = (int) $rooms->count();

        return Inertia::render('Reports/RoomStatus', [
            'rows' => $rows,
            'counts' => $counts,
            'currency' => $this->currency(),
        ]);
    }

    /** Raporti i Pastrimit: cleaning tasks in range (by created_at) — per-staff productivity + recent task list. */
    public function housekeepingReport(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $tasks = CleaningTask::with(['room:id,room_number', 'assignedUser:id,name'])
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->orderByDesc('created_at')
            ->get(['id', 'room_id', 'assigned_to', 'type', 'status', 'priority', 'completed_at', 'created_at']);

        $completedStatuses = ['completed', 'inspected'];
        $pendingStatuses = ['pending', 'in_progress'];

        // Per-staff productivity (group in PHP — cross-DB safe).
        $byStaff = $tasks
            ->groupBy(fn ($t) => $t->assignedUser?->name ?: 'Pa caktuar')
            ->map(function ($group, $staff) use ($completedStatuses, $pendingStatuses) {
                return [
                    'staff' => $staff,
                    'total' => $group->count(),
                    'completed' => $group->whereIn('status', $completedStatuses)->count(),
                    'pending' => $group->whereIn('status', $pendingStatuses)->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        // Recent task list (already ordered by created_at desc; cap to 50 rows).
        $recent = $tasks->take(50)->map(fn ($t) => [
            'id' => $t->id,
            'room' => $t->room?->room_number ?? '—',
            'type' => $t->type,
            'status' => $t->status,
            'priority' => $t->priority,
            'assigned' => $t->assignedUser?->name ?: 'Pa caktuar',
            'created' => $t->created_at?->toDateString(),
        ])->values();

        return Inertia::render('Reports/Housekeeping', [
            'filters' => ['from' => $from, 'to' => $to],
            'byStaff' => $byStaff,
            'recent' => $recent,
            'summary' => [
                'total' => $tasks->count(),
                'completed' => $tasks->whereIn('status', $completedStatuses)->count(),
                'pending' => $tasks->whereIn('status', $pendingStatuses)->count(),
            ],
            'currency' => $this->currency(),
        ]);
    }

    public function inHouse(Request $request): Response
    {
        $reservations = Reservation::with(['room.roomType', 'guest'])
            ->where('status', 'checked_in')
            ->get()
            ->sortBy(fn ($r) => $r->room?->room_number ?? '')
            ->values();

        $rows = $reservations->map(function ($r) {
            $guest = $r->guest;
            $name = $guest
                ? trim(($guest->first_name ?? '').' '.($guest->last_name ?? ''))
                : '';

            return [
                'id' => $r->id,
                'guest' => $name !== '' ? $name : '—',
                'phone' => $guest?->phone,
                'room' => $r->room?->room_number,
                'room_type' => $r->room?->roomType?->name,
                'check_in' => optional($r->check_in_date)->toDateString(),
                'check_out' => optional($r->check_out_date)->toDateString(),
                'nights' => $r->nights,
                'adults' => (int) ($r->adults ?? 0),
                'children' => (int) ($r->children ?? 0),
                'pax' => (int) ($r->adults ?? 0) + (int) ($r->children ?? 0),
            ];
        })->values();

        $summary = [
            'count' => $rows->count(),
            'pax' => $rows->sum('pax'),
        ];

        return Inertia::render('Reports/InHouse', [
            'rows' => $rows,
            'summary' => $summary,
            'currency' => $this->currency(),
        ]);
    }

    /** Discounts given (folio_items type=discount) in a date range. */
    public function discounts(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $rows = FolioItem::where('type', 'discount')
            ->whereBetween('charge_date', [$from, $to])
            ->with([
                'reservation:id,guest_id,room_id',
                'reservation.guest:id,first_name,last_name',
                'reservation.room:id,room_number',
            ])
            ->orderByDesc('charge_date')->orderByDesc('id')->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'reservation_id' => $f->reservation_id,
                'guest' => trim("{$f->reservation?->guest?->first_name} {$f->reservation?->guest?->last_name}") ?: '—',
                'room' => $f->reservation?->room?->room_number,
                'description' => $f->description,
                'date' => $f->charge_date?->toDateString(),
                'amount' => (float) $f->amount,
            ]);

        return Inertia::render('Reports/Discounts', [
            'filters' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'total' => round((float) $rows->sum('amount'), 2),
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

    private function posBusinessRange($query, string $from, string $to)
    {
        return $query->where(function ($range) use ($from, $to) {
            $range->where(function ($business) use ($from, $to) {
                $business->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);
            })
                ->orWhere(function ($legacy) use ($from, $to) {
                    $legacy->whereNull('business_date')
                        ->whereBetween('paid_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                })
                ->orWhere(function ($legacy) use ($from, $to) {
                    $legacy->whereNull('business_date')->whereNull('paid_at')
                        ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                });
        });
    }

    private function currency(): string
    {
        return BaseCurrency::symbol();
    }
}
