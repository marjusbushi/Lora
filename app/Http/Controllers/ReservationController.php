<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationStoreRequest;
use App\Http\Requests\ReservationUpdateRequest;
use App\Models\AuditLog;
use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use App\Services\RoomPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Reservation::select(
            'id', 'room_id', 'guest_id', 'check_in_date', 'check_out_date',
            'status', 'total_amount', 'adults', 'children', 'channel', 'created_at'
        )
            ->with(['room:id,room_number', 'room.roomType:id,name', 'guest:id,first_name,last_name'])
            ->orderByDesc('check_in_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('guest', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        return Inertia::render('Reservations/Index', [
            'reservations' => $query->paginate(15),
            'rooms' => Room::select('id', 'room_number', 'room_type_id')
                ->with('roomType:id,name,base_price,max_occupancy')
                ->orderBy('room_number')
                ->get(),
            'guests' => Guest::select('id', 'first_name', 'last_name', 'email', 'phone')
                ->orderBy('last_name')
                ->get(),
            'filters' => $request->only('status', 'search'),
            'channelFees' => Setting::get('financial.channel_fees', []),
            'stats' => [
                'total' => Reservation::count(),
                'pending' => Reservation::where('status', 'pending')->count(),
                'confirmed' => Reservation::where('status', 'confirmed')->count(),
                'checked_in' => Reservation::where('status', 'checked_in')->count(),
            ],
        ]);
    }

    public function calendar(Request $request): Response
    {
        $startDate = $request->input('start', now()->startOfWeek()->toDateString());
        $endDate = now()->parse($startDate)->addDays(13)->toDateString();

        $rooms = Room::select('id', 'room_number', 'room_type_id', 'floor', 'status')
            ->with('roomType:id,name,base_price,max_occupancy')
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get();

        $reservations = Reservation::select(
            'id', 'room_id', 'guest_id', 'check_in_date', 'check_out_date', 'status',
            'total_amount', 'adults', 'children', 'channel', 'notes', 'booking_group_id'
        )
            ->with('guest:id,first_name,last_name,phone,email')
            ->whereNotIn('status', ['cancelled'])
            ->where('check_in_date', '<=', $endDate)
            ->where('check_out_date', '>=', $startDate)
            ->get()
            // Send plain local Y-m-d (not ISO UTC datetimes) so the calendar's
            // string date comparisons line up — fixes the off-by-one / out-of-sync bars.
            ->map(fn ($r) => [
                'id' => $r->id,
                'room_id' => $r->room_id,
                'guest_id' => $r->guest_id,
                'check_in_date' => $r->check_in_date->toDateString(),
                'check_out_date' => $r->check_out_date->toDateString(),
                'status' => $r->status,
                'total_amount' => $r->total_amount,
                'adults' => $r->adults,
                'children' => $r->children,
                'channel' => $r->channel,
                'notes' => $r->notes,
                'booking_group_id' => $r->booking_group_id,
                'guest' => $r->guest ? [
                    'id' => $r->guest->id,
                    'first_name' => $r->guest->first_name,
                    'last_name' => $r->guest->last_name,
                    'phone' => $r->guest->phone,
                    'email' => $r->guest->email,
                ] : null,
            ]);

        $guests = Guest::select('id', 'first_name', 'last_name', 'email', 'phone')
            ->orderBy('last_name')
            ->get();

        return Inertia::render('Reservations/Calendar', [
            'rooms' => $rooms,
            'reservations' => $reservations,
            'guests' => $guests,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'channelFees' => Setting::get('financial.channel_fees', []),
        ]);
    }

    public function show(Reservation $reservation): Response
    {
        $reservation->load([
            'room:id,room_number,room_type_id',
            'room.roomType:id,name,base_price',
            'guest:id,first_name,last_name,email,phone',
            'folioItems' => fn($q) => $q->orderBy('charge_date')->orderBy('id'),
            'payments' => fn($q) => $q->orderBy('created_at'),
        ]);

        // Balance = room charge + folio charges - discounts - payments.
        // total_amount stays the ROOM charge; the live balance is computed here.
        $roomCharge = (float) $reservation->total_amount;
        // total_amount already represents the room charge ("Qendrimi ne dhome"); any type='room'
        // folio lines (e.g. per-night room lines) would double-count it, so exclude them everywhere.
        $folioCharges = (float) $reservation->folioItems->whereNotIn('type', ['discount', 'room'])->sum('amount');
        $discounts = (float) $reservation->folioItems->where('type', 'discount')->sum('amount');
        $gross = round($roomCharge + $folioCharges - $discounts, 2);

        // Menu/room prices are treated as VAT-INCLUSIVE (Albanian norm: shown price = paid price).
        // We surface the tax portion without inflating what the guest owes.
        $taxRate = (float) Setting::get('financial.tax_rate', 20);
        $taxAmount = $taxRate > 0 ? round($gross - ($gross / (1 + $taxRate / 100)), 2) : 0.0;

        $paid = (float) $reservation->payments->reject(fn ($p) => $p->is_voided)->sum('amount');
        $outstanding = round($gross - $paid, 2);

        $openPosOrders = PosOrder::where('reservation_id', $reservation->id)
            ->where('status', 'open')
            ->select('id', 'table_number', 'total_amount', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Reservations/Show', [
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'check_in_date' => $reservation->check_in_date?->toDateString(),
                'check_out_date' => $reservation->check_out_date?->toDateString(),
                'nights' => $reservation->nights,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'notes' => $reservation->notes,
                'channel' => $reservation->channel,
                'channel_ref' => $reservation->channel_ref,
                'payment_collect' => $reservation->payment_collect,
                'guest' => [
                    'name' => $reservation->guest?->full_name,
                    'email' => $reservation->guest?->email,
                    'phone' => $reservation->guest?->phone,
                ],
                'room' => [
                    'room_number' => $reservation->room?->room_number,
                    'room_type' => $reservation->room?->roomType?->name,
                ],
            ],
            'folio' => [
                'roomCharge' => $roomCharge,
                'items' => $reservation->folioItems
                    ->where('type', '!=', 'room')
                    ->values()
                    ->map(fn($i) => [
                        'id' => $i->id,
                        'description' => $i->description,
                        'type' => $i->type,
                        'amount' => (float) $i->amount,
                        'charge_date' => $i->charge_date?->toDateString(),
                    ]),
                'discounts' => round($discounts, 2),
                'gross' => $gross,
                'taxRate' => $taxRate,
                'taxAmount' => $taxAmount,
                'net' => round($gross - $taxAmount, 2),
                'paid' => round($paid, 2),
                'outstanding' => $outstanding,
            ],
            'payments' => $reservation->payments->map(fn($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'date' => $p->created_at?->toDateString(),
            ]),
            'openPosOrders' => $openPosOrders,
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
        ]);
    }

    public function addFolioLine(Request $request, Reservation $reservation): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:restaurant,bar,minibar,extra,discount'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'charge_date' => ['nullable', 'date'],
        ]);

        $reservation->folioItems()->create([
            'description' => $data['description'],
            'amount' => $data['amount'],
            'type' => $data['type'],
            'charge_date' => $data['charge_date'] ?? today(),
        ]);

        AuditLog::record('folio.add_line', $reservation, ['type' => $data['type'], 'amount' => $data['amount']]);

        return back()->with('success', 'Rreshti u shtua ne folio.');
    }

    public function recordPayment(Request $request, Reservation $reservation): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', 'in:cash,card'],
        ]);

        $reservation->payments()->create([
            'amount' => $data['amount'],
            'method' => $data['method'],
            'created_by' => auth()->id(),
        ]);

        AuditLog::record('payment.record', $reservation, ['amount' => $data['amount'], 'method' => $data['method']]);

        return back()->with('success', 'Pagesa u regjistrua.');
    }

    public function store(ReservationStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $room = Room::with('roomType')->findOrFail($data['room_id']);

        $checkIn = now()->parse($data['check_in_date']);
        $checkOut = now()->parse($data['check_out_date']);
        $nights = $checkIn->diffInDays($checkOut);

        // Price: staff may enter the gross amount (OTA price, fee included); else default to room rate × nights.
        $entered = $data['total_amount'] ?? null;
        $data['total_amount'] = is_numeric($entered) && (float) $entered > 0
            ? round((float) $entered, 2)
            : RoomPricing::total($room->roomType, $checkIn, $checkOut);
        $data['created_by'] = auth()->id();
        $data['status'] = $data['status'] ?? 'pending';
        $data['channel'] = $data['channel'] ?? 'manual';
        // Commission is ALWAYS derived server-side from the channel's configured % (locked).
        $data['commission_amount'] = $this->channelCommission($data['channel'], (float) $data['total_amount']);

        try {
            // The FormRequest already pre-checks availability; re-check under a row lock
            // inside the transaction to close the check-then-write race (no double-book).
            DB::transaction(function () use ($data, $room) {
                Room::where('id', $room->id)->lockForUpdate()->first();

                if (!Reservation::isRoomAvailable($room->id, $data['check_in_date'], $data['check_out_date'])) {
                    throw new \RuntimeException('room_unavailable');
                }

                Reservation::create($data);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Dhoma nuk eshte e disponueshme per keto data.');
        }

        return back()->with('success', 'Rezervimi u krijua me sukses.');
    }

    /**
     * Multi-room booking: one guest, N rooms, one reservation per room — all
     * sharing dates/channel and (when >1 room) a common booking_group_id so the
     * rooms can be managed together later. The guest is referenced once (not
     * duplicated). All-or-nothing: any unavailable/over-capacity room rolls back.
     */
    public function storeMulti(Request $request): RedirectResponse
    {
        // Staff may back-date a booking (walk-in that already arrived, historical
        // entry) — no after_or_equal:today here. The PUBLIC website stays future-only.
        $data = $request->validate([
            'guest_id' => ['required', 'exists:guests,id'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'status' => ['sometimes', 'in:pending,confirmed'],
            'channel' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(Reservation::CHANNELS)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_id' => ['required', 'exists:rooms,id'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:20'],
            'rooms.*.children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rooms.*.total_amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'guest_id.required' => 'Zgjidh nje mysafir.',
            'check_in_date.required' => 'Vendos daten e hyrjes (check-in).',
            'check_out_date.required' => 'Vendos daten e daljes (check-out).',
            'check_out_date.after' => 'Data e daljes duhet te jete pas dates se hyrjes.',
            'rooms.required' => 'Shto te pakten nje dhome.',
            'rooms.min' => 'Shto te pakten nje dhome.',
            'rooms.*.room_id.required' => 'Zgjidh dhomen per cdo rresht.',
        ]);

        // No duplicate room within the same booking.
        $roomIds = array_column($data['rooms'], 'room_id');
        if (count($roomIds) !== count(array_unique($roomIds))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'rooms' => 'Nuk mund te zgjedhesh te njejten dhome dy here ne te njejtin rezervim.',
            ]);
        }

        $nights = now()->parse($data['check_in_date'])->diffInDays(now()->parse($data['check_out_date']));
        $channel = $data['channel'] ?? 'manual';
        $status = $data['status'] ?? 'pending';
        $multi = count($data['rooms']) > 1;

        try {
            DB::transaction(function () use ($data, $nights, $channel, $status, $multi) {
                $groupId = $multi ? (string) \Illuminate\Support\Str::uuid() : null;

                foreach ($data['rooms'] as $row) {
                    $room = Room::with('roomType')->findOrFail($row['room_id']);

                    // Capacity safety net (the UI also caps this).
                    $maxOcc = $room->roomType?->max_occupancy;
                    $persons = (int) $row['adults'] + (int) ($row['children'] ?? 0);
                    if ($maxOcc && $persons > $maxOcc) {
                        throw new \RuntimeException("over_capacity:{$room->room_number}:{$maxOcc}");
                    }

                    // A room under maintenance is out of service regardless of dates —
                    // give a clear reason (not the misleading "booked for these dates").
                    if ($room->status === 'maintenance') {
                        throw new \RuntimeException("maintenance:{$room->room_number}");
                    }

                    // Row lock + re-check availability inside the transaction (no double-book).
                    Room::where('id', $room->id)->lockForUpdate()->first();
                    if (!Reservation::isRoomAvailable($room->id, $data['check_in_date'], $data['check_out_date'])) {
                        throw new \RuntimeException("room_unavailable:{$room->room_number}");
                    }

                    $entered = $row['total_amount'] ?? null;
                    $total = is_numeric($entered) && (float) $entered > 0
                        ? round((float) $entered, 2)
                        : RoomPricing::total($room->roomType, $data['check_in_date'], $data['check_out_date']);

                    Reservation::create([
                        'room_id' => $room->id,
                        'guest_id' => $data['guest_id'],
                        'created_by' => auth()->id(),
                        'check_in_date' => $data['check_in_date'],
                        'check_out_date' => $data['check_out_date'],
                        'status' => $status,
                        'total_amount' => $total,
                        'adults' => $row['adults'],
                        'children' => $row['children'] ?? 0,
                        'notes' => $data['notes'] ?? null,
                        'channel' => $channel,
                        'commission_amount' => $this->channelCommission($channel, (float) $total),
                        'booking_group_id' => $groupId,
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            // Business failures come back as a 422 validation error (keyed 'rooms')
            // so Inertia fires onError — NOT onSuccess. Returning a redirect here
            // would look like success to the client and falsely close the modal.
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'over_capacity:')) {
                [, $rn, $cap] = explode(':', $msg);
                $err = "Dhoma {$rn} lejon maksimumi {$cap} persona.";
            } elseif (str_starts_with($msg, 'maintenance:')) {
                [, $rn] = explode(':', $msg);
                $err = "Dhoma {$rn} eshte ne mirembajtje. Ndrysho statusin e dhomes te 'Dhomat' per ta rezervuar.";
            } elseif (str_starts_with($msg, 'room_unavailable:')) {
                [, $rn] = explode(':', $msg);
                $err = "Dhoma {$rn} eshte e zene per keto data (ka nje rezervim tjeter).";
            } else {
                $err = 'Rezervimi nuk u krijua.';
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['rooms' => $err]);
        }

        $count = count($data['rooms']);
        return back()->with('success', $count > 1
            ? "U krijuan {$count} rezervime per kete mysafir."
            : 'Rezervimi u krijua me sukses.');
    }

    public function update(ReservationUpdateRequest $request, Reservation $reservation): RedirectResponse
    {
        $data = $request->validated();
        $room = Room::with('roomType')->findOrFail($data['room_id']);

        $checkIn = now()->parse($data['check_in_date']);
        $checkOut = now()->parse($data['check_out_date']);
        $nights = $checkIn->diffInDays($checkOut);
        $entered = $data['total_amount'] ?? null;
        $data['total_amount'] = is_numeric($entered) && (float) $entered > 0
            ? round((float) $entered, 2)
            : RoomPricing::total($room->roomType, $checkIn, $checkOut);
        $data['channel'] = $data['channel'] ?? $reservation->channel ?? 'manual';
        $data['commission_amount'] = $this->channelCommission($data['channel'], (float) $data['total_amount']);

        $reservation->update($data);

        return back()->with('success', 'Rezervimi u perditesua.');
    }

    /**
     * Seasonal price quote for the create/edit form. The client sends only the
     * room + dates; the amount is computed SERVER-SIDE from RoomPricing (seasons +
     * rate overrides). It never accepts a price from the client — this only feeds
     * the form's suggested value, and the store/update paths recompute it anyway.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'exists:rooms,id'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ]);

        $room = Room::with('roomType')->findOrFail($data['room_id']);
        $quote = RoomPricing::quote($room->roomType, $data['check_in'], $data['check_out']);

        return response()->json([
            'nights' => $quote['nights'],
            'total' => $quote['total'],
        ]);
    }

    /**
     * Commission a channel keeps on a booking, from the configured % (settings
     * financial.channel_fees). manual/direct or any unconfigured channel = 0.
     */
    private function channelCommission(?string $channel, float $total): float
    {
        $fees = (array) Setting::get('financial.channel_fees', []);
        $pct = isset($fees[$channel]) && is_numeric($fees[$channel]) ? (float) $fees[$channel] : 0.0;

        return round($total * $pct / 100, 2);
    }

    public function checkIn(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'confirmed') {
            return back()->with('error', 'Vetem rezervimet e konfirmuara mund te bejne check-in.');
        }

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'checked_in']);
            $reservation->room->update(['status' => 'occupied']);
        });

        AuditLog::record('reservation.check_in', $reservation, ['room' => $reservation->room->room_number]);

        return back()->with('success', "Check-in per dhomen {$reservation->room->room_number} u krye.");
    }

    /**
     * Move a CHECKED-IN guest to a different room (upgrade / room problem). Only the
     * room changes — dates, guest, folio and total stay the same. The new room must be
     * free for the stay dates; the old room goes to cleaning + a housekeeping task.
     */
    public function moveRoom(Request $request, Reservation $reservation): RedirectResponse
    {
        if (!auth()->user()->can('update_reservations')) {
            abort(403);
        }

        if ($reservation->status !== 'checked_in') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'room_id' => 'Zhvendosja e dhomes lejohet vetem per mysafiret brenda (checked-in). Perndryshe perdor Edito.',
            ]);
        }

        $data = $request->validate([
            'room_id' => ['required', 'exists:rooms,id'],
        ]);

        if ((int) $data['room_id'] === (int) $reservation->room_id) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'room_id' => 'Zgjidh nje dhome tjeter nga ajo aktuale.',
            ]);
        }

        $newRoom = Room::with('roomType')->findOrFail($data['room_id']);
        $oldRoom = $reservation->room;

        try {
            DB::transaction(function () use ($reservation, $newRoom, $oldRoom) {
                Room::where('id', $newRoom->id)->lockForUpdate()->first();

                if (!Reservation::isRoomAvailable(
                    $newRoom->id,
                    $reservation->check_in_date->toDateString(),
                    $reservation->check_out_date->toDateString(),
                    $reservation->id
                )) {
                    throw new \RuntimeException('unavailable');
                }

                $reservation->update(['room_id' => $newRoom->id]);
                $newRoom->update(['status' => 'occupied']);

                // The room the guest left needs cleaning — mirror check-out's housekeeping.
                if ($oldRoom) {
                    $oldRoom->update(['status' => 'cleaning']);

                    if (Setting::get('housekeeping.auto_create_on_checkout', true)) {
                        $alreadyOpen = CleaningTask::where('room_id', $oldRoom->id)
                            ->where('type', 'checkout_clean')
                            ->whereIn('status', ['pending', 'in_progress'])
                            ->exists();

                        if (!$alreadyOpen) {
                            CleaningTask::create([
                                'room_id' => $oldRoom->id,
                                'type' => 'checkout_clean',
                                'status' => 'pending',
                                'priority' => Setting::get('housekeeping.default_priority', 'normal'),
                            ]);
                        }
                    }
                }
            });
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'room_id' => "Dhoma {$newRoom->room_number} nuk eshte e lire per keto data.",
            ]);
        }

        AuditLog::record('reservation.move_room', $reservation, [
            'from' => $oldRoom?->room_number,
            'to' => $newRoom->room_number,
        ]);

        return back()->with('success', "Mysafiri u zhvendos te dhoma {$newRoom->room_number}.");
    }

    public function checkOut(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'checked_in') {
            return back()->with('error', 'Vetem mysafiret brenda mund te bejne check-out.');
        }

        // Don't let a guest leave with an unsettled bar/restaurant tab still open.
        $openOrders = PosOrder::where('reservation_id', $reservation->id)
            ->where('status', 'open')
            ->count();
        if ($openOrders > 0) {
            return back()->with('error', "Ka {$openOrders} porosi POS te hapura per kete rezervim — mbyllini perpara check-out.");
        }

        // Checkout settles the bill: the invoice is marked paid (cash/card) and only THEN does the guest leave.
        $data = $request->validate([
            'settle_method' => ['nullable', 'in:cash,card'],
        ]);

        DB::transaction(function () use ($reservation, $data) {
            // Record a payment for whatever is still owed, with the chosen method, before flipping status.
            if (!empty($data['settle_method'])) {
                $reservation->loadMissing('folioItems', 'payments');
                $roomCharge = (float) $reservation->total_amount;
                $folioCharges = (float) $reservation->folioItems->whereNotIn('type', ['discount', 'room'])->sum('amount');
                $discounts = (float) $reservation->folioItems->where('type', 'discount')->sum('amount');
                $paid = (float) $reservation->payments->reject(fn ($p) => $p->is_voided)->sum('amount');
                $outstanding = round($roomCharge + $folioCharges - $discounts - $paid, 2);

                if ($outstanding > 0) {
                    $reservation->payments()->create([
                        'amount' => $outstanding,
                        'method' => $data['settle_method'],
                        'created_by' => auth()->id(),
                    ]);
                    AuditLog::record('payment.record', $reservation, [
                        'amount' => $outstanding,
                        'method' => $data['settle_method'],
                        'context' => 'checkout_settle',
                    ]);
                }
            }

            $reservation->update(['status' => 'checked_out']);
            $reservation->room->update(['status' => 'cleaning']);

            // Auto-create a housekeeping task so the cleaning board reflects the checkout
            // (activates the housekeeping.auto_create_on_checkout setting, previously dead).
            if (Setting::get('housekeeping.auto_create_on_checkout', true)) {
                $alreadyOpen = CleaningTask::where('room_id', $reservation->room_id)
                    ->where('type', 'checkout_clean')
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->exists();

                if (!$alreadyOpen) {
                    CleaningTask::create([
                        'room_id' => $reservation->room_id,
                        'type' => 'checkout_clean',
                        'status' => 'pending',
                        'priority' => Setting::get('housekeeping.default_priority', 'normal'),
                    ]);
                }
            }
        });

        AuditLog::record('reservation.check_out', $reservation, ['room' => $reservation->room->room_number]);

        return back()->with('success', "Check-out per dhomen {$reservation->room->room_number} u krye.");
    }

    /**
     * Front desk requests a stayover (daily) cleaning while the guest is still in-house.
     * Creates a stayover_clean task WITHOUT ending the stay or touching room status —
     * the guest stays put, the room stays occupied. Can be requested again next day.
     */
    public function requestCleaning(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'checked_in') {
            return back()->with('error', 'Pastrimi ditor mund te kerkohet vetem kur klienti eshte brenda (check-in).');
        }

        // One open stayover task per room is enough — don't stack duplicates.
        $alreadyOpen = CleaningTask::where('room_id', $reservation->room_id)
            ->where('type', 'stayover_clean')
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
        if ($alreadyOpen) {
            return back()->with('error', 'Ka tashme nje pastrim ditor ne pritje per kete dhome.');
        }

        CleaningTask::create([
            'room_id' => $reservation->room_id,
            'type' => 'stayover_clean',
            'status' => 'pending',
            'priority' => Setting::get('housekeeping.default_priority', 'normal'),
            'notes' => 'Kerkuar nga recepsioni per klientin ne dhome.',
        ]);

        AuditLog::record('housekeeping.stayover_requested', $reservation, ['room' => $reservation->room->room_number]);

        return back()->with('success', 'Pastrimi ditor u kerkua — housekeeping do ta shohe ne board.');
    }

    public function cancel(Reservation $reservation): RedirectResponse
    {
        if (in_array($reservation->status, ['checked_in', 'checked_out'])) {
            return back()->with('error', 'Nuk mund te anulohet nje rezervim aktiv ose i perfunduar.');
        }

        $reservation->update(['status' => 'cancelled']);

        AuditLog::record('reservation.cancel', $reservation);

        return back()->with('success', 'Rezervimi u anulua.');
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        if (!auth()->user()->can('delete_reservations')) {
            abort(403);
        }

        $reservation->delete();

        return back()->with('success', 'Rezervimi u fshi.');
    }
}
