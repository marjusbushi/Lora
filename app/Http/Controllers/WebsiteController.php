<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\PokClient;
use App\Services\RoomPricing;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function home(): Response
    {
        $roomTypes = RoomType::select('id', 'name', 'description', 'base_price', 'max_occupancy', 'amenities', 'breakfast_included')
            ->withCount('rooms')
            ->with('images')
            ->get();

        // Public "Nga €X" = the lowest price a guest could pay (base or any season rate).
        $roomTypes->each(fn ($rt) => $rt->base_price = RoomPricing::fromPrice($rt));

        return Inertia::render('Website/Home', [
            'roomTypes' => $roomTypes,
            'hotel' => Setting::getGroup('hotel'),
        ]);
    }

    public function rooms(): Response
    {
        $roomTypes = RoomType::select('id', 'name', 'description', 'base_price', 'max_occupancy', 'amenities', 'breakfast_included')
            ->withCount(['rooms', 'rooms as available_count' => fn($q) => $q->where('status', 'available')])
            ->with('images')
            ->get();

        $roomTypes->each(fn ($rt) => $rt->base_price = RoomPricing::fromPrice($rt));

        return Inertia::render('Website/Rooms', [
            'roomTypes' => $roomTypes,
        ]);
    }

    public function bookingForm(Request $request): Response
    {
        $roomTypes = RoomType::select('id', 'name', 'base_price', 'max_occupancy')
            ->get();

        // Show the same lowest "Nga €X" figure the homepage/rooms cards show, so a room
        // type never shows two different headline prices across pages. The binding price
        // is still computed per-night by checkAvailability (RoomPricing::total).
        $roomTypes->each(fn ($rt) => $rt->base_price = RoomPricing::fromPrice($rt));

        return Inertia::render('Website/Book', [
            'roomTypes' => $roomTypes,
            'preselectedType' => $request->input('room_type'),
            'hotel' => Setting::getGroup('hotel'),
        ]);
    }

    public function checkAvailability(Request $request)
    {
        $request->validate([
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'room_type_id' => ['nullable', 'exists:room_types,id'],
        ]);

        $query = Room::select('id', 'room_number', 'room_type_id', 'floor')
            ->with('roomType:id,name,base_price,max_occupancy,amenities')
            ->where('status', '!=', 'maintenance');

        if ($request->filled('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        $rooms = $query->get()->filter(function ($room) use ($request) {
            return Reservation::isRoomAvailable($room->id, $request->check_in, $request->check_out);
        })->values();

        $nights = now()->parse($request->check_in)->diffInDays($request->check_out);

        return response()->json([
            'rooms' => $rooms->map(function ($r) use ($request, $nights) {
                $total = RoomPricing::total($r->roomType, $request->check_in, $request->check_out);

                return [
                    'id' => $r->id,
                    'room_number' => $r->room_number,
                    'floor' => $r->floor,
                    'room_type' => $r->roomType->name,
                    'price_per_night' => $nights > 0 ? round($total / $nights, 2) : (float) $r->roomType->base_price,
                    'total_price' => $total,
                    'max_occupancy' => $r->roomType->max_occupancy,
                    'amenities' => $r->roomType->amenities,
                ];
            }),
            'nights' => $nights,
        ]);
    }

    /**
     * Per-day free-room count for the availability calendar on /book.
     * free(day) = bookable rooms (of the type, excl. maintenance) minus the
     * distinct rooms with a non-cancelled reservation covering that night.
     */
    public function availability(Request $request)
    {
        $request->validate([
            'room_type_id' => ['nullable', 'exists:room_types,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $today = now()->startOfDay();
        $from = $request->filled('from') ? now()->parse($request->from)->startOfDay() : $today->copy();
        if ($from->lt($today)) {
            $from = $today->copy();
        }
        $to = $request->filled('to') ? now()->parse($request->to)->startOfDay() : $from->copy()->addDays(60);
        // Clamp the window so one request can't scan an unbounded range.
        if ($to->lt($from)) {
            $to = $from->copy();
        }
        if ($to->gt($from->copy()->addDays(120))) {
            $to = $from->copy()->addDays(120);
        }

        $roomQuery = Room::where('status', '!=', 'maintenance');
        if ($request->filled('room_type_id')) {
            $roomQuery->where('room_type_id', $request->room_type_id);
        }
        $roomIds = $roomQuery->pluck('id');
        $total = $roomIds->count();

        // Exclude cancelled AND checked_out — must match Reservation::isRoomAvailable
        // (the real booking engine) so the calendar never disagrees with /book/check.
        $reservations = Reservation::whereIn('room_id', $roomIds)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->where('check_in_date', '<=', $to->toDateString())
            ->where('check_out_date', '>', $from->toDateString())
            ->get(['room_id', 'check_in_date', 'check_out_date']);

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $occupied = $reservations
                ->filter(fn ($r) => $d->betweenIncluded($r->check_in_date, $r->check_out_date->copy()->subDay()))
                ->pluck('room_id')->unique()->count();
            $days[$d->toDateString()] = max(0, $total - $occupied);
        }

        return response()->json(['total' => $total, 'days' => $days]);
    }

    public function submitBooking(Request $request): RedirectResponse
    {
        // Honeypot — bots fill this hidden field; real visitors never do.
        if ($request->filled('website')) {
            return redirect()->route('website.home');
        }

        $request->validate([
            'room_id' => ['required', 'exists:rooms,id'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:10'],
        ]);

        $room = Room::with('roomType')->findOrFail($request->room_id);

        if ($room->roomType && ((int) $request->adults + (int) $request->children) > $room->roomType->max_occupancy) {
            return back()->with('error', "Kjo dhome lejon maksimumi {$room->roomType->max_occupancy} persona.");
        }

        $nights = now()->parse($request->check_in)->diffInDays($request->check_out);

        // Attribute public bookings to a stable system user (self-seeding) — never a hardcoded
        // id, so a missing/renumbered user 1 can't 500 the public booking funnel.
        $creator = User::firstOrCreate(
            ['email' => 'system@villamucho.local'],
            ['name' => 'Website Booking', 'password' => Str::random(40)]
        );

        try {
            // Lock the room row + re-check availability INSIDE the transaction so two
            // concurrent bookings for the same room can't both pass the check (no double-book).
            $reservation = DB::transaction(function () use ($request, $room, $nights, $creator) {
                Room::where('id', $room->id)->lockForUpdate()->first();

                if (!Reservation::isRoomAvailable($room->id, $request->check_in, $request->check_out)) {
                    throw new \RuntimeException('room_unavailable');
                }

                // Match an existing guest by normalized email and REUSE it WITHOUT
                // overwriting their saved data: a public (unauthenticated) booking must
                // not be able to tamper with an existing guest's name/phone/nationality.
                // The fields below apply ONLY when creating a brand-new guest.
                $guest = Guest::firstOrCreate(
                    ['email' => strtolower(trim($request->email))],
                    [
                        'first_name' => $request->first_name,
                        'last_name' => $request->last_name,
                        'phone' => $request->phone,
                        'nationality' => $request->nationality,
                    ]
                );

                return Reservation::create([
                    'room_id' => $room->id,
                    'guest_id' => $guest->id,
                    'check_in_date' => $request->check_in,
                    'check_out_date' => $request->check_out,
                    'status' => 'pending',
                    'total_amount' => RoomPricing::total($room->roomType, $request->check_in, $request->check_out),
                    'adults' => $request->adults,
                    'children' => (int) $request->children,
                    'notes' => $request->notes,
                    'channel' => 'direct', // booked on villamucho.com
                    'created_by' => $creator->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Kjo dhome nuk eshte me e disponueshme per keto data.');
        }

        $guestName = trim("{$request->first_name} {$request->last_name}");

        // Full prepayment (MANDATORY when POK is configured): create the order and send the
        // guest to the embedded card form. If POK is NOT configured, fall back to the old
        // no-payment confirmation so the public site never breaks before go-live.
        $pok = app(PokClient::class);
        if ($pok->configured() && (float) $reservation->total_amount > 0) {
            try {
                $order = $pok->createOrder((float) $reservation->total_amount, 'EUR', [
                    'webhook' => route('website.pay.webhook'),
                    'redirect' => route('website.booking.confirmation', $reservation->confirmation_token),
                    'fail' => route('website.pay.show', $reservation->confirmation_token),
                    'expires' => 30,
                ]);
                $reservation->update(['pok_order_id' => $order['id']]);

                return redirect()->route('website.pay.show', $reservation->confirmation_token)
                    ->with('book_guest_name', $guestName);
            } catch (\Throwable $e) {
                report($e);
                // Couldn't reach POK — release the just-held room and ask the guest to retry.
                $reservation->update(['status' => 'cancelled']);

                return back()->with('error', 'Nuk u lidh dot pagesa me kartë. Provo sërish pas pak.');
            }
        }

        // Flash the name the booker just typed so the confirmation can greet THEM
        // without reading the stored guest's name (which may belong to someone else).
        return redirect()->route('website.booking.confirmation', $reservation->confirmation_token)
            ->with('book_guest_name', $guestName);
    }

    /** The embedded POK card-payment page for a pending reservation. */
    public function bookingPayment(string $token): Response|RedirectResponse
    {
        $reservation = Reservation::where('confirmation_token', $token)
            ->with('room.roomType')
            ->firstOrFail();

        // Already paid/confirmed (or never had an order) → straight to confirmation.
        if ($reservation->status !== 'pending' || ! $reservation->pok_order_id) {
            return redirect()->route('website.booking.confirmation', $token);
        }

        return Inertia::render('Website/BookingPayment', [
            'orderId' => $reservation->pok_order_id,
            'env' => config('services.pok.production') ? 'production' : 'staging',
            'amount' => (float) $reservation->total_amount,
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
            'guestName' => session('book_guest_name'),
            'confirmUrl' => route('website.pay.confirm', $token),
            'roomName' => $reservation->room?->roomType?->name,
            'nights' => (int) now()->parse($reservation->check_in_date)->diffInDays($reservation->check_out_date),
        ]);
    }

    /** Browser calls this after the embedded form fires onSuccess — verify + confirm. */
    public function confirmPayment(string $token): RedirectResponse
    {
        $reservation = Reservation::where('confirmation_token', $token)->firstOrFail();

        try {
            $this->markReservationPaid($reservation);
        } catch (\Throwable $e) {
            report($e); // never 500 the guest — the webhook is the backstop
        }

        if ($reservation->fresh()->status === 'confirmed') {
            return redirect()->route('website.booking.confirmation', $token);
        }

        return redirect()->route('website.pay.show', $token)
            ->with('error', "Pagesa s'u konfirmua ende. Nëse e paguat, prit pak sekonda dhe rifresko.");
    }

    /** POK server-to-server webhook (CSRF-exempt). Never trusts the body — re-verifies. */
    public function paymentWebhook(Request $request): \Illuminate\Http\Response
    {
        $orderId = $request->input('id')
            ?? $request->input('sdkOrderId')
            ?? $request->input('orderId')
            ?? data_get($request->all(), 'data.sdkOrder.id')
            ?? data_get($request->all(), 'data.id');

        if ($orderId) {
            $reservation = Reservation::where('pok_order_id', $orderId)->first();
            if ($reservation) {
                try {
                    $this->markReservationPaid($reservation);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        // POK retries on non-2xx — always acknowledge.
        return response('ok', 200);
    }

    /**
     * Verify the POK order and, if genuinely paid, atomically confirm the reservation +
     * record the folio card payment. Idempotent + hardened (R1–R7 from last session's review):
     * amount+currency verified, atomic status guard (no late-webhook resurrection, no double
     * confirm), unique pok_order_id backstops a double folio row.
     */
    private function markReservationPaid(Reservation $reservation): bool
    {
        if (! $reservation->pok_order_id) {
            return false;
        }

        $order = app(PokClient::class)->getOrder($reservation->pok_order_id);
        $expected = round((float) $reservation->total_amount, 2);

        $paid = $order['isCompleted']
            && ! $order['isCanceled']
            && ! $order['isRefunded']
            && abs($order['finalAmount'] - $expected) < 0.01          // R2 amount bypass
            && strtoupper($order['currencyCode']) === 'EUR';          // R6 currency

        if (! $paid) {
            return false;
        }

        // Atomic guard (R1 resurrection + R3 SQLite lock no-op): only a still-PENDING, unpaid
        // reservation flips. A released/cancelled hold or an already-confirmed one affects 0 rows.
        $flipped = Reservation::whereKey($reservation->id)
            ->where('status', 'pending')
            ->whereNull('paid_at')
            ->update(['status' => 'confirmed', 'paid_at' => now()]);

        if ($flipped !== 1) {
            return false; // already settled or released — idempotent no-op
        }

        try {
            Payment::create([
                'reservation_id' => $reservation->id,
                'amount' => $expected,
                'method' => 'card',
                'type' => 'payment',
                'pok_order_id' => $reservation->pok_order_id,
                'currency' => 'EUR',
                'created_by' => $reservation->created_by,
            ]);
        } catch (QueryException $e) {
            // UNIQUE(pok_order_id) — a concurrent path already recorded this order's payment.
        }

        AuditLog::record('payment.pok_capture', $reservation, [
            'pok_order_id' => $reservation->pok_order_id,
            'amount' => $expected,
            'currency' => 'EUR',
        ]);

        return true;
    }

    public function bookingConfirmation(string $token): Response
    {
        // Look up by the unguessable token, never by the sequential id (IDOR-safe).
        $reservation = Reservation::where('confirmation_token', $token)
            ->with(['room.roomType', 'guest'])
            ->firstOrFail();

        // Pass ONLY the fields this page renders — never the full Guest model
        // (document_number, date_of_birth, etc. must not reach the public props).
        return Inertia::render('Website/BookingConfirmation', [
            'reservation' => [
                'reference' => strtoupper(substr($reservation->confirmation_token, 0, 8)),
                // The booker's submitted name (flashed) — NOT the stored guest's name,
                // which could belong to a different person if the email already existed.
                // Null on a later refresh (flash gone) -> the confirmation hides the row.
                'guest_name' => session('book_guest_name'),
                'room_number' => $reservation->room?->room_number,
                'room_type' => $reservation->room?->roomType?->name,
                'check_in_date' => $reservation->check_in_date?->toDateString(),
                'check_out_date' => $reservation->check_out_date?->toDateString(),
                'total_amount' => $reservation->total_amount,
            ],
            'hotel' => Setting::getGroup('hotel'),
        ]);
    }

    public function about(): Response
    {
        return Inertia::render('Website/About', [
            'hotel' => Setting::getGroup('hotel'),
            'about' => Setting::getGroup('about'),
        ]);
    }

    public function contact(): Response
    {
        return Inertia::render('Website/Contact', [
            'hotel' => Setting::getGroup('hotel'),
        ]);
    }

    public function submitContact(Request $request): RedirectResponse
    {
        // Honeypot — silently accept (so bots don't learn) but do nothing.
        if ($request->filled('website')) {
            return back()->with('success', 'Faleminderit! Mesazhi juaj u derua me sukses.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        // For now, just log it. Later: send email or save to DB
        \Illuminate\Support\Facades\Log::info('Contact form submission', $request->only('name', 'email', 'message'));

        return back()->with('success', 'Faleminderit! Mesazhi juaj u derua me sukses.');
    }
}
