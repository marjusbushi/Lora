<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderRound;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\Reservation;
use App\Services\BaseCurrency;
use App\Services\CurrencyRates;
use App\Services\InventoryLedger;
use App\Services\PosSalespersonService;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosTableServiceController extends Controller
{
    public function __construct(
        private readonly InventoryLedger $inventoryLedger,
        private readonly PosSalespersonService $posSalespeople,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // Direct-mode hotels route NEW sales to the sale screen — but an
        // explicit deep-link to a table (Paguaj on a legacy table order after
        // the hotel switched modes) must still open, or those open orders
        // become unpayable and uncancelable from the UI.
        if ($this->posSalespeople->settings()['service_mode'] === 'direct' && ! $request->integer('table')) {
            return redirect()->route('pos.index', ['direct' => 1]);
        }

        $this->ensureDefaultTables();

        // Same device-outlet contract as PosController::resolveOutlet — the
        // session key is shared so the waiter's pick follows them across the
        // sale and tables screens.
        $outlets = PosOutlet::active()->ordered()->get(['id', 'name']);
        $currentOutlet = null;
        if ($outlets->isNotEmpty()) {
            $currentOutlet = ($request->integer('outlet') ? $outlets->firstWhere('id', $request->integer('outlet')) : null)
                ?? $outlets->firstWhere('id', (int) $request->session()->get('pos.outlet_id'))
                ?? $outlets->first();
            $request->session()->put('pos.outlet_id', $currentOutlet->id);
        } else {
            $request->session()->forget('pos.outlet_id');
        }

        $tables = PosTable::query()
            ->where('is_active', true)
            ->when($currentOutlet, fn ($query) => $query->where(fn ($q) => $q
                ->whereNull('outlet_id')
                ->orWhere('outlet_id', $currentOutlet->id)))
            ->orderBy('area')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->get();

        $openOrders = PosOrder::query()
            ->where('status', 'open')
            ->whereNotNull('pos_table_id')
            ->with([
                'createdBy:id,name',
                'salesperson:id,name',
                'items.menuItem:id,name',
                'rounds.createdBy:id,name',
                'rounds.items.menuItem:id,name',
            ])
            ->get()
            ->keyBy('pos_table_id');

        $payload = $tables->map(function (PosTable $table) use ($openOrders) {
            $order = $openOrders->get($table->id);

            return [
                'id' => $table->id,
                'number' => $table->number,
                'name' => $table->name,
                'area' => $table->area,
                'seats' => $table->seats,
                'status' => ! $order ? 'free' : ($order->service_status === 'bill_requested' ? 'bill_requested' : 'occupied'),
                'open_order' => $order ? $this->orderPayload($order) : null,
            ];
        })->values();

        $activeReservations = Reservation::where('status', 'checked_in')
            ->with(['room:id,room_number', 'guest:id,first_name,last_name'])
            ->select('id', 'room_id', 'guest_id')
            ->get()
            ->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'label' => 'Dhoma '.$reservation->room?->room_number.' — '.trim($reservation->guest?->first_name.' '.$reservation->guest?->last_name),
            ])->values();

        return Inertia::render('Pos/Tables', [
            'tables' => $payload,
            'areas' => $tables->pluck('area')->unique()->values(),
            'outlets' => $outlets->map(fn (PosOutlet $outlet) => ['id' => $outlet->id, 'name' => $outlet->name])->values(),
            'currentOutletId' => $currentOutlet?->id,
            'activeReservations' => $activeReservations,
            'currentShift' => ($shift = PosShift::currentFor($request->user()->id)) ? [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->format('H:i'),
                'user_name' => $request->user()->name,
            ] : null,
            'currency' => BaseCurrency::code(),
            'payCurrencies' => CurrencyRates::payable(),
            'printRoundId' => $request->session()->pull('pos_print_round_id'),
            'selectedTableId' => $request->integer('table') ?: null,
            'autoAction' => $request->string('action')->toString(),
            'currentSalesperson' => ($salesperson = $this->posSalespeople->current($request))->only(['id', 'name']),
            'salespeople' => $this->posSalespeople->staff()->where('enabled', true)->values(),
            'posSettings' => $this->posSalespeople->settings(),
            'stats' => [
                'total' => $tables->count(),
                'occupied' => $payload->where('status', 'occupied')->count(),
                'bill_requested' => $payload->where('status', 'bill_requested')->count(),
                'open_total' => round((float) $openOrders->sum('total_amount'), 2),
            ],
        ]);
    }

    public function storeRound(Request $request, PosTable $posTable): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', TenantRule::exists('menu_items')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'send' => ['required', 'boolean'],
            'covers' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $shift = PosShift::currentFor($request->user()->id);
        if (! $shift) {
            return back()->with('error', 'Hap një turn para se të regjistrosh porosi.');
        }

        [$order, $round] = DB::transaction(function () use ($request, $posTable, $data, $shift) {
            $table = PosTable::query()->lockForUpdate()->findOrFail($posTable->id);
            $order = PosOrder::query()->where('pos_table_id', $table->id)->where('status', 'open')->lockForUpdate()->first();

            if (! $order) {
                // A table's own outlet is the truth for table service; a
                // shared (outlet-less) table falls back to the device outlet.
                $outletId = $table->outlet_id
                    ?: PosOutlet::active()->whereKey((int) $request->session()->get('pos.outlet_id'))->value('id');

                $order = PosOrder::create([
                    'pos_table_id' => $table->id,
                    'table_number' => $table->number,
                    'pos_shift_id' => $shift->id,
                    'outlet_id' => $outletId,
                    'status' => 'open',
                    'service_status' => 'open',
                    'covers' => $data['covers'] ?? null,
                    'created_by' => $request->user()->id,
                    'salesperson_id' => $this->posSalespeople->current($request)->id,
                    'total_amount' => 0,
                ]);
            }

            $sequence = ((int) $order->rounds()->max('sequence')) + 1;
            $sent = (bool) $data['send'];
            $round = PosOrderRound::create([
                'pos_order_id' => $order->id,
                'sequence' => $sequence,
                'status' => $sent ? 'sent' : 'draft',
                'destination' => 'banak',
                'sent_at' => $sent ? now() : null,
                'printed_at' => $sent ? now() : null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $line) {
                $menuItem = MenuItem::query()->findOrFail($line['menu_item_id']);
                $orderItem = PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'pos_order_round_id' => $round->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $menuItem->price,
                    'total_price' => (float) $menuItem->price * $line['quantity'],
                ]);
                $this->inventoryLedger->consumePosOrderItem($orderItem, $request->user()->id);
            }

            $order->update(['service_status' => 'open', 'pos_shift_id' => $shift->id]);
            $order->recalculateTotal();

            return [$order, $round];
        });

        AuditLog::record('pos.round.created', $order, [
            'round_id' => $round->id,
            'sequence' => $round->sequence,
            'status' => $round->status,
            'table_id' => $posTable->id,
        ]);

        if ($round->status === 'sent') {
            $request->session()->flash('pos_print_round_id', $round->id);
        }

        return redirect()->route('pos.tables', ['table' => $posTable->id])
            ->with('success', $round->status === 'sent'
                ? "Porosia #{$round->sequence} u dërgua dhe është gati për printim."
                : "Porosia #{$round->sequence} u ruajt pa u dërguar.");
    }

    public function sendRound(Request $request, PosOrderRound $posOrderRound): RedirectResponse
    {
        if ($posOrderRound->status !== 'draft') {
            return back()->with('error', 'Kjo porosi është dërguar më parë.');
        }

        $shift = PosShift::currentFor($request->user()->id);
        if (! $shift) {
            return back()->with('error', 'Hap një turn para se të dërgosh porosinë.');
        }

        $posOrderRound->update([
            'status' => 'sent',
            'sent_at' => now(),
            'printed_at' => now(),
        ]);
        $posOrderRound->order()->update(['service_status' => 'open']);

        AuditLog::record('pos.round.sent', $posOrderRound->order, ['round_id' => $posOrderRound->id]);
        $request->session()->flash('pos_print_round_id', $posOrderRound->id);

        return redirect()->route('pos.tables', ['table' => $posOrderRound->order->pos_table_id])
            ->with('success', "Porosia #{$posOrderRound->sequence} u dërgua dhe është gati për printim.");
    }

    public function requestBill(Request $request, PosTable $posTable): RedirectResponse
    {
        $order = PosOrder::where('pos_table_id', $posTable->id)->where('status', 'open')->first();
        if (! $order) {
            return back()->with('error', 'Kjo tavolinë nuk ka llogari të hapur.');
        }

        $order->update(['service_status' => $order->service_status === 'bill_requested' ? 'open' : 'bill_requested']);
        AuditLog::record('pos.table.bill_status', $order, ['service_status' => $order->service_status]);

        return redirect()->route('pos.tables', ['table' => $posTable->id]);
    }

    public function transfer(Request $request, PosTable $posTable): RedirectResponse
    {
        $data = $request->validate(['destination_table_id' => ['required', 'integer', 'different:'.$posTable->id, TenantRule::exists('pos_tables')]]);
        [$order, $destination] = DB::transaction(function () use ($data, $posTable) {
            // Locking the destination table serializes simultaneous transfers to the
            // same free table, so only one open account can claim it.
            $destination = PosTable::query()
                ->lockForUpdate()
                ->findOrFail($data['destination_table_id']);

            $order = PosOrder::query()
                ->where('pos_table_id', $posTable->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw ValidationException::withMessages(['table' => 'Kjo tavolinë nuk ka llogari të hapur.']);
            }
            if (PosOrder::where('pos_table_id', $destination->id)->where('status', 'open')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['destination_table_id' => 'Tavolina e zgjedhur është e zënë.']);
            }

            $order->update([
                'pos_table_id' => $destination->id,
                'table_number' => $destination->number,
                'service_status' => 'open',
            ]);

            return [$order, $destination];
        });

        AuditLog::record('pos.table.transferred', $order, ['from' => $posTable->id, 'to' => $destination->id]);

        return redirect()->route('pos.tables', ['table' => $destination->id])
            ->with('success', "Llogaria u transferua te {$destination->name}.");
    }

    private function ensureDefaultTables(): void
    {
        if (PosTable::query()->exists()) {
            return;
        }

        foreach (range(1, 10) as $number) {
            PosTable::create([
                'number' => (string) $number,
                'name' => "Tavolina {$number}",
                'area' => 'Salla kryesore',
                'seats' => in_array($number, [2, 7], true) ? 2 : 4,
                'sort_order' => $number,
                'is_active' => true,
            ]);
        }
    }

    private function orderPayload(PosOrder $order): array
    {
        $rounds = $order->rounds->map(fn (PosOrderRound $round) => [
            'id' => $round->id,
            'sequence' => $round->sequence,
            'status' => $round->status,
            'destination' => $round->destination,
            'sent_at' => $round->sent_at?->toIso8601String(),
            'printed_at' => $round->printed_at?->toIso8601String(),
            'created_at' => $round->created_at?->toIso8601String(),
            'created_by' => $round->createdBy?->name,
            'total' => round((float) $round->items->sum('total_price'), 2),
            'items' => $round->items->map(fn (PosOrderItem $item) => [
                'id' => $item->id,
                'name' => $item->menuItem?->name ?: 'Artikull POS',
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ])->values(),
        ]);

        $legacyItems = $order->items->whereNull('pos_order_round_id');
        if ($legacyItems->isNotEmpty()) {
            $rounds->prepend([
                'id' => null,
                'sequence' => 1,
                'status' => 'sent',
                'destination' => 'banak',
                'sent_at' => $order->created_at?->toIso8601String(),
                'printed_at' => null,
                'created_at' => $order->created_at?->toIso8601String(),
                'created_by' => $order->createdBy?->name,
                'total' => round((float) $legacyItems->sum('total_price'), 2),
                'items' => $legacyItems->map(fn (PosOrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->menuItem?->name ?: 'Artikull POS',
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                ])->values(),
            ]);
        }

        return [
            'id' => $order->id,
            'table_number' => $order->table_number,
            'status' => $order->status,
            'service_status' => $order->service_status,
            'covers' => $order->covers,
            'total_amount' => (float) $order->total_amount,
            'created_at' => $order->created_at?->toIso8601String(),
            'created_by' => $order->createdBy?->name,
            'salesperson' => $order->salesperson
                ? ['id' => $order->salesperson->id, 'name' => $order->salesperson->name]
                : ($order->createdBy ? ['id' => $order->createdBy->id, 'name' => $order->createdBy->name] : null),
            'rounds' => $rounds->values(),
        ];
    }
}
