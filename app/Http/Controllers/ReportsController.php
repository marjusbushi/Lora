<?php

namespace App\Http\Controllers;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\BaseCurrency;
use App\Services\Reporting\BookingBehaviorService;
use App\Services\Reporting\BudgetTargetService;
use App\Services\Reporting\CancellationRiskService;
use App\Services\Reporting\ChannelPerformanceService;
use App\Services\Reporting\DepartmentRevenueService;
use App\Services\Reporting\DiscountRefundCashFlowService;
use App\Services\Reporting\FiscalVatReportService;
use App\Services\Reporting\GuestLifetimeValueService;
use App\Services\Reporting\GuestMovementService;
use App\Services\Reporting\GuestSegmentationService;
use App\Services\Reporting\HotelKpiService;
use App\Services\Reporting\HousekeepingProductivityService;
use App\Services\Reporting\MaintenanceSlaReportService;
use App\Services\Reporting\OperationsExecutiveService;
use App\Services\Reporting\OutstandingBalanceService;
use App\Services\Reporting\PaymentReconciliationService;
use App\Services\Reporting\PickupPaceService;
use App\Services\Reporting\PosControlReportService;
use App\Services\Reporting\PosPerformanceService;
use App\Services\Reporting\RecurringMaintenanceIssueService;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\RoomReadinessService;
use App\Services\Reporting\RoomTypePerformanceService;
use App\Services\Reporting\ShiftReportService;
use App\Services\Reporting\StayRevenueAllocator;
use App\Services\Reporting\StockValuationReportService;
use App\Services\Reporting\SupplierPerformanceReportService;
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
    public function shifts(Request $request, ShiftReportService $shiftReport): Response
    {
        [$from, $to] = $this->range($request);
        $analytics = $shiftReport->summary(new ReportingPeriod($from, $to));

        return Inertia::render('Reports/Shifts', [
            'filters' => ['from' => $from, 'to' => $to],
            ...$analytics,
            'currency' => $this->currency(),
        ]);
    }

    /** Guest directory / CRM: per-guest stays, nights, spend and visit history. */
    public function guests(Request $request, GuestLifetimeValueService $lifetimeValue): Response
    {
        $analytics = $lifetimeValue->summary(null);
        $nationalities = Guest::query()->pluck('nationality', 'id');
        $rows = collect($analytics['guests'])->map(fn (array $guest) => [
            'id' => $guest['id'],
            'guest' => $guest['guest'],
            'email' => $guest['email'],
            'phone' => $guest['phone'],
            'nationality' => $nationalities->get($guest['id']) ?: '—',
            'stays' => $guest['stays'],
            'nights' => $guest['nights'],
            'total_spent' => $guest['net_value'],
            'last_visit' => $guest['last_visit'],
            'first_seen' => $guest['first_visit'],
        ])->values();

        return Inertia::render('Reports/Guests', [
            'rows' => $rows,
            'summary' => [
                'total_guests' => $rows->count(),
                'repeat_guests' => $rows->filter(fn ($r) => $r['stays'] >= 2)->count(),
                'total_nights' => (int) $rows->sum('nights'),
                'total_revenue' => $analytics['summary']['net_lifetime_value'],
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** POS performance: sales, margin, hourly demand, categories and top items. */
    public function posSales(Request $request, PosPerformanceService $report): Response
    {
        [$from, $to] = $this->range($request);
        $analytics = $report->withComparison(new ReportingPeriod($from, $to));
        $current = $analytics['current'];

        return Inertia::render('Reports/PosSales', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $analytics,
            'byCategory' => $current['categories'],
            'topItems' => $current['top_items'],
            'summary' => $current['summary'],
            'currency' => $this->currency(),
        ]);
    }

    /** Arrivals manifest: every booked arrival in range with guest, room, pax, channel and balance owed. */
    public function arrivalsManifest(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $arrivals = Reservation::whereBetween('check_in_date', [$from, $to])
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out', 'pending'])
            ->whereNull('no_show_at')
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
            ->select('reservation_id', DB::raw("SUM(CASE WHEN COALESCE(type, 'payment') IN ('payment', 'deposit', 'writeoff') THEN amount WHEN type = 'refund' THEN -ABS(amount) ELSE 0 END) as paid"))
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
                'gross' => $gross,
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
                'revenue' => round((float) $rows->sum('gross'), 2),
                'balance' => round((float) $rows->sum(fn (array $row) => max(0, (float) $row['balance'])), 2),
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
            ->select('reservation_id', DB::raw("SUM(CASE WHEN COALESCE(type, 'payment') IN ('payment', 'deposit', 'writeoff') THEN amount WHEN type = 'refund' THEN -ABS(amount) ELSE 0 END) as paid"))
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
                'outstanding' => round((float) $rows->sum(fn (array $row) => max(0, (float) $row['balance'])), 2),
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

    /** Anulime & No-Show: realized losses plus a deterministic operational risk score. */
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
        $overdueArrivals = collect($current['at_risk'])
            ->filter(fn (array $row) => in_array('arrival_overdue', $row['risk_drivers'], true))
            ->values();

        // Preserve the original report contract used by dashboard action links.
        // In that contract, "no show" means unresolved past-arrival candidates.
        $legacySummary = [
            'cancelled_count' => $current['summary']['cancelled_count'],
            'cancelled_value' => $current['summary']['cancelled_value'],
            'total_count' => $current['summary']['total_count'],
            'cancellation_rate' => $current['summary']['cancellation_rate'],
            'no_show_count' => $overdueArrivals->count(),
            'no_show_value' => round((float) $overdueArrivals->sum('value'), 2),
        ];

        return Inertia::render('Reports/Cancellations', [
            'filters' => $period->toArray(),
            'analytics' => $analytics,
            'summary' => $legacySummary,
            'cancelled' => collect($current['losses'])->where('type', 'cancelled')->values(),
            'noShows' => $overdueArrivals,
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
    public function repeatGuests(Request $request, GuestLifetimeValueService $report): Response
    {
        $analytics = $report->summary();

        return Inertia::render('Reports/RepeatGuests', [
            'analytics' => $analytics,
            'canViewGuests' => $request->user()?->can('view_guests') ?? false,
            'currency' => $this->currency(),
        ]);
    }

    public function guestSegments(Request $request, GuestSegmentationService $report): Response
    {
        $request->validate(['segment' => ['nullable', 'in:all,vip,loyal,returning,new,dormant']]);

        return Inertia::render('Reports/GuestSegments', [
            'analytics' => $report->summary($request->input('segment', 'all')),
            'canViewGuests' => $request->user()?->can('view_guests') ?? false,
            'currency' => $this->currency(),
        ]);
    }

    /** Guest mix by nationality: distinct guests, stays, nights, revenue, ALOS. */
    public function nationality(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $reservations = Reservation::whereBetween('check_in_date', [$from, $to])
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereNull('no_show_at')
            ->whereHas('guest')
            ->with('guest:id,nationality')
            ->get(['id', 'guest_id', 'status', 'check_in_date', 'check_out_date', 'total_amount']);

        $rows = $reservations
            ->groupBy(fn ($r) => ($r->guest && filled($r->guest->nationality)) ? $r->guest->nationality : 'E panjohur')
            ->map(function ($group, $nationality) {
                $stays = $group->count();
                $nights = (int) $group->sum(function (Reservation $reservation) {
                    if ($reservation->status === 'checked_out') {
                        return $reservation->nights;
                    }

                    return max(0, $reservation->check_in_date->diffInDays(today()->min($reservation->check_out_date), false));
                });
                $revenue = (float) $group->sum(function (Reservation $reservation) {
                    if ($reservation->status === 'checked_out') {
                        return (float) $reservation->total_amount;
                    }

                    $nights = max(1, $reservation->nights);
                    $realized = max(0, $reservation->check_in_date->diffInDays(today()->min($reservation->check_out_date), false));

                    return (float) $reservation->total_amount * min(1, $realized / $nights);
                });
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

    /** POS sales and refund events by hour-of-day and weekday. */
    public function posHourly(Request $request, PosPerformanceService $report): Response
    {
        [$from, $to, $days] = $this->range($request);
        $analytics = $report->summary(new ReportingPeriod($from, $to));
        $weekdayLabels = [1 => 'Hën', 2 => 'Mar', 3 => 'Mër', 4 => 'Enj', 5 => 'Pre', 6 => 'Sht', 7 => 'Die'];
        $byHour = collect($analytics['hours'])->map(fn (array $row) => [
            'hour' => $row['hour'],
            'count' => $row['orders'],
            'revenue' => $row['revenue'],
        ])->values();
        $byWeekday = collect($analytics['weekdays'])->map(fn (array $row) => [
            'weekday' => $weekdayLabels[$row['weekday']],
            'count' => $row['orders'],
            'revenue' => $row['revenue'],
        ])->values();

        return Inertia::render('Reports/PosHourly', [
            'filters' => ['from' => $from, 'to' => $to],
            'byHour' => $byHour,
            'byWeekday' => $byWeekday,
            'summary' => [
                'total_revenue' => $analytics['summary']['total_revenue'],
                'order_count' => $analytics['summary']['order_count'],
                'days' => $days,
            ],
            'currency' => $this->currency(),
        ]);
    }

    /** POS controls: payment mix, refunds, voids and operator exceptions. */
    public function posPaymentMix(Request $request, PosControlReportService $report): Response
    {
        [$from, $to] = $this->range($request);
        $analytics = $report->withComparison(new ReportingPeriod($from, $to));

        return Inertia::render('Reports/PosPaymentMix', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $analytics,
            'currency' => $this->currency(),
        ]);
    }

    public function posVoids(Request $request, PosControlReportService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/PosPaymentMix', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->withComparison(new ReportingPeriod($from, $to)),
            'currency' => $this->currency(),
        ]);
    }

    public function stockValuation(Request $request, StockValuationReportService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/StockValuation', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->withComparison(new ReportingPeriod($from, $to)),
            'currency' => $this->currency(),
        ]);
    }

    public function supplierPerformance(Request $request, SupplierPerformanceReportService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/SupplierPerformance', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->withComparison(new ReportingPeriod($from, $to)),
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

    /** Housekeeping productivity, queue time and cleaning turnaround. */
    public function housekeepingReport(Request $request, HousekeepingProductivityService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/Housekeeping', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->summary(new ReportingPeriod($from, $to)),
            'currency' => $this->currency(),
        ]);
    }

    public function maintenanceSla(Request $request, MaintenanceSlaReportService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/MaintenanceSla', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->summary(new ReportingPeriod($from, $to)),
            'canViewMaintenance' => $request->user()?->can('view_maintenance') ?? false,
            'currency' => $this->currency(),
        ]);
    }

    public function recurringMaintenance(Request $request, RecurringMaintenanceIssueService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/RecurringMaintenance', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->summary(new ReportingPeriod($from, $to)),
            'canViewMaintenance' => $request->user()?->can('view_maintenance') ?? false,
            'currency' => $this->currency(),
        ]);
    }

    public function roomReadiness(Request $request, RoomReadinessService $report): Response
    {
        return Inertia::render('Reports/RoomReadiness', [
            'analytics' => $report->snapshot(
                $request->user()?->can('view_reservations') ?? false,
                $request->user()?->can('view_housekeeping') ?? false,
            ),
            'permissions' => [
                'reservations' => $request->user()?->can('view_reservations') ?? false,
                'housekeeping' => $request->user()?->can('view_housekeeping') ?? false,
            ],
            'currency' => $this->currency(),
        ]);
    }

    public function operationsExecutive(Request $request, OperationsExecutiveService $report): Response
    {
        $permissions = [
            'reservations' => $request->user()?->can('view_reservations') ?? false,
            'housekeeping' => $request->user()?->can('view_housekeeping') ?? false,
            'maintenance' => $request->user()?->can('view_maintenance') ?? false,
        ];

        return Inertia::render('Reports/OperationsExecutive', [
            'analytics' => $report->snapshot(
                $permissions['reservations'],
                $permissions['housekeeping'],
                $permissions['maintenance'],
            ),
            'permissions' => $permissions,
            'currency' => $this->currency(),
        ]);
    }

    public function guestMovements(Request $request, GuestMovementService $report): Response
    {
        $request->validate(['tab' => ['nullable', 'in:arrivals,departures,in_house']]);
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/GuestMovements', [
            'filters' => ['from' => $from, 'to' => $to],
            'activeTab' => $request->input('tab', 'arrivals'),
            'analytics' => $report->summary(new ReportingPeriod($from, $to)),
            'currency' => $this->currency(),
        ]);
    }

    public function inHouse(Request $request, GuestMovementService $report): Response
    {
        $analytics = $report->summary(new ReportingPeriod(today()->toDateString(), today()->toDateString()));

        return Inertia::render('Reports/InHouse', [
            'rows' => $analytics['in_house'],
            'summary' => $analytics['summary']['in_house'],
            'currency' => $this->currency(),
        ]);
    }

    /** Discounts, refunds and real ledger cash flow in a date range. */
    public function discounts(Request $request, DiscountRefundCashFlowService $report): Response
    {
        [$from, $to] = $this->range($request);
        $analytics = $report->summary(new ReportingPeriod($from, $to));

        return Inertia::render('Reports/Discounts', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $analytics,
            'canViewReservations' => $request->user()?->can('view_reservations') ?? false,
            'canViewPos' => $request->user()?->can('view_pos_orders') ?? false,
            'currency' => $this->currency(),
        ]);
    }

    /** Recognized net revenue split between Rooms, POS/F&B and other charges. */
    public function departmentRevenue(Request $request, DepartmentRevenueService $report): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/DepartmentRevenue', [
            'filters' => ['from' => $from, 'to' => $to],
            'analytics' => $report->withComparison(new ReportingPeriod($from, $to)),
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
        return BaseCurrency::symbol();
    }
}
