<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FolioItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Setting;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PosOrder::select(
            'id', 'reservation_id', 'table_number', 'status',
            'payment_method', 'total_amount', 'created_by', 'created_at'
        )
            ->with(['createdBy:id,name', 'items.menuItem:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Active checked-in reservations for room charge
        $activeReservations = Reservation::where('status', 'checked_in')
            ->with(['room:id,room_number', 'guest:id,first_name,last_name'])
            ->select('id', 'room_id', 'guest_id')
            ->get();

        // Current user's open cash-drawer shift (per-user model), with live running totals.
        $shift = PosShift::currentFor(auth()->id());
        $currentShift = null;
        if ($shift) {
            $byMethod = $shift->orders()
                ->where('status', 'completed')
                ->selectRaw('payment_method, COUNT(*) as cnt, SUM(total_amount) as sum')
                ->groupBy('payment_method')
                ->get();
            $cash = (float) ($byMethod->firstWhere('payment_method', 'cash')->sum ?? 0);
            $card = (float) ($byMethod->firstWhere('payment_method', 'card')->sum ?? 0);
            $room = (float) ($byMethod->firstWhere('payment_method', 'room_charge')->sum ?? 0);

            $currentShift = [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->format('H:i'),
                'opening_float' => (float) $shift->opening_float,
                'user_name' => auth()->user()->name,
                'open_orders' => (int) $shift->orders()->where('status', 'open')->count(),
                'completed_orders' => (int) $byMethod->sum('cnt'),
                'cash_sales' => $cash,
                'card_sales' => $card,
                'room_charge_sales' => $room,
                // Only cash drives the drawer; card + room_charge are reported separately.
                'expected_cash' => round((float) $shift->opening_float + $cash, 2),
            ];
        }

        return Inertia::render('Pos/Index', [
            'orders' => $query->paginate(15),
            'menu' => MenuCategory::with(['items' => fn ($q) => $q->where('is_available', true)])
                ->orderBy('sort_order')
                ->get(),
            'activeReservations' => $activeReservations,
            'filters' => $request->only('status'),
            'currentShift' => $currentShift,
            'canOpenShift' => $request->user()->can('open_pos_shift'),
            'canCloseShift' => $request->user()->can('close_pos_shift'),
            'defaultOpeningFloat' => (float) Setting::get('pos.default_opening_float', 0),
            'stats' => [
                'open' => PosOrder::where('status', 'open')->count(),
                'today_completed' => PosOrder::where('status', 'completed')->whereDate('created_at', today())->count(),
                'today_revenue' => PosOrder::where('status', 'completed')->whereDate('created_at', today())->sum('total_amount'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'table_number' => ['nullable', 'string', 'max:10'],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', TenantRule::exists('menu_items')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        // No order without an open cash-drawer shift for the acting user.
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', null, ['attempted_action' => 'store']);

            return back()->with('error', 'Hap nje turn para se te krijosh porosi.');
        }

        $order = DB::transaction(function () use ($request, $shift) {
            $order = PosOrder::create([
                'table_number' => $request->table_number,
                'reservation_id' => $request->reservation_id,
                'pos_shift_id' => $shift->id,
                'status' => 'open',
                'created_by' => auth()->id(),
                'total_amount' => 0,
            ]);

            foreach ($request->items as $item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);
                PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $menuItem->price,
                    'total_price' => $menuItem->price * $item['quantity'],
                ]);
            }

            $order->recalculateTotal();

            return $order;
        });

        return back()->with('success', "Porosia #{$order->id} u krijua — €{$order->total_amount}");
    }

    public function complete(Request $request, PosOrder $posOrder): RedirectResponse
    {
        $request->validate([
            'payment_method' => ['required', 'in:cash,card,room_charge'],
            // Only a currently checked-in reservation can be charged — never an arbitrary id (IDOR guard).
            'reservation_id' => [
                'nullable',
                'required_if:payment_method,room_charge',
                TenantRule::exists('reservations')->where('status', 'checked_in'),
            ],
        ]);

        if ($posOrder->status !== 'open') {
            return back()->with('error', 'Kjo porosi nuk eshte e hapur.');
        }

        // A sale only finalizes inside the acting user's open shift (cash hits a live drawer).
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', $posOrder, ['attempted_action' => 'complete']);

            return back()->with('error', 'Hap nje turn para se te mbyllesh porosine.');
        }

        DB::transaction(function () use ($posOrder, $request, $shift) {
            // Room charge can target a reservation chosen at payment time;
            // otherwise keep the one set when the order was created.
            $reservationId = $request->payment_method === 'room_charge'
                ? $request->reservation_id
                : $posOrder->reservation_id;

            $posOrder->update([
                'status' => 'completed',
                'payment_method' => $request->payment_method,
                'reservation_id' => $reservationId,
                'paid_at' => now(),
                'business_date' => today(),
                // Cash physically enters the drawer of whoever finalizes the sale, so attribute the
                // order to the completing user's shift — fixes cross-shift completion + legacy NULL orders.
                'pos_shift_id' => $shift->id,
            ]);

            // Room charge → add a traceable line to the reservation folio
            if ($request->payment_method === 'room_charge') {
                $posOrder->loadMissing('items.menuItem.category');
                FolioItem::create([
                    'reservation_id' => $reservationId,
                    'pos_order_id' => $posOrder->id,
                    'description' => "POS Porosi #{$posOrder->id}".($posOrder->table_number ? " (Tavolina {$posOrder->table_number})" : ''),
                    'amount' => $posOrder->total_amount,
                    'type' => $posOrder->items->first()?->menuItem?->category?->name === 'Pije' ? 'bar' : 'restaurant',
                    'charge_date' => today(),
                ]);
            }
        });

        AuditLog::record('pos.complete', $posOrder, [
            'amount' => $posOrder->total_amount,
            'payment_method' => $request->payment_method,
            'reservation_id' => $posOrder->reservation_id,
        ]);

        return back()->with('success', 'Porosia u perfundua.');
    }

    public function cancel(PosOrder $posOrder): RedirectResponse
    {
        if ($posOrder->status !== 'open') {
            return back()->with('error', 'Vetem porosite e hapura mund te anulohen.');
        }

        // Voiding a ticket is a cash-control event — only inside the acting user's open shift.
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', $posOrder, ['attempted_action' => 'cancel']);

            return back()->with('error', 'Hap nje turn para se te anulosh porosine.');
        }

        $posOrder->update(['status' => 'cancelled', 'pos_shift_id' => $shift->id]);

        AuditLog::record('pos.cancel', $posOrder, ['amount' => $posOrder->total_amount]);

        return back()->with('success', 'Porosia u anulua.');
    }
}
