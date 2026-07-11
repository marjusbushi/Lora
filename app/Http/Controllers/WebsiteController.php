<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteSearchLog;
use App\Services\PokClient;
use App\Services\PokConfiguration;
use App\Services\PokPayments;
use App\Services\PublicRoomPricing;
use App\Services\RoomPricing;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function __construct(private readonly PublicRoomPricing $publicRoomPricing) {}

    public function home(): Response
    {
        $roomTypes = RoomType::select('id', 'name', 'description', 'base_price', 'max_occupancy', 'amenities', 'breakfast_included')
            ->withCount('rooms')
            ->with('images')
            ->get();

        $this->attachPublicFromPrices($roomTypes);

        return Inertia::render('Website/Home', [
            'roomTypes' => $roomTypes,
            'hotel' => Setting::getGroup('hotel'),
        ]);
    }

    public function rooms(): Response
    {
        $roomTypes = RoomType::select('id', 'name', 'description', 'base_price', 'max_occupancy', 'amenities', 'breakfast_included')
            ->withCount(['rooms', 'rooms as available_count' => fn ($q) => $q->where('status', 'available')])
            ->with('images')
            ->get();

        $this->attachPublicFromPrices($roomTypes);

        return Inertia::render('Website/Rooms', [
            'roomTypes' => $roomTypes,
        ]);
    }

    public function bookingForm(Request $request): Response
    {
        $roomTypes = RoomType::select('id', 'name', 'base_price', 'max_occupancy')
            ->get();

        // Keep base_price intact and expose a separate public headline derived
        // from the same effective date prices sent to OTAs.
        $this->attachPublicFromPrices($roomTypes);

        return Inertia::render('Website/Book', [
            'roomTypes' => $roomTypes,
            'preselectedType' => $request->input('room_type'),
            'hotel' => Setting::getGroup('hotel'),
            // A card-payment step follows the form → the submit button says "Vazhdo te pagesa"
            // instead of "Konfirmo", so the money step is never a surprise.
            'paymentRequired' => app(PokClient::class)->configured(),
        ]);
    }

    /** @param Collection<int, RoomType> $roomTypes */
    private function attachPublicFromPrices($roomTypes): void
    {
        $fromPrices = $this->publicRoomPricing->fromPrices($roomTypes);
        $roomTypes->each(fn (RoomType $roomType) => $roomType->setAttribute(
            'from_price',
            $fromPrices[$roomType->id] ?? null,
        ));
    }

    public function checkAvailability(Request $request)
    {
        $request->validate([
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'room_type_id' => ['nullable', TenantRule::exists('room_types')],
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

        // Demand signal for pricing (searches + denials). Best-effort: a
        // logging failure must never break the guest's availability check.
        try {
            WebsiteSearchLog::create([
                'check_in' => $request->check_in,
                'check_out' => $request->check_out,
                'room_type_id' => $request->room_type_id ?: null,
                'results_count' => $rooms->count(),
                'denied' => $rooms->isEmpty(),
                'source' => 'book',
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Website search log failed: '.$e->getMessage());
        }

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
            'room_type_id' => ['nullable', TenantRule::exists('room_types')],
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
            'room_id' => ['required', TenantRule::exists('rooms')],
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

        // A VALIDATION error (not a flash) so Inertia preserves the wizard's state — the guest
        // keeps everything typed and recovers in-step instead of being reset to step 1.
        if ($room->roomType && ((int) $request->adults + (int) $request->children) > $room->roomType->max_occupancy) {
            throw ValidationException::withMessages([
                'room_id' => "Kjo dhomë lejon maksimumi {$room->roomType->max_occupancy} persona — zgjidh një dhomë më të madhe.",
            ]);
        }

        $nights = now()->parse($request->check_in)->diffInDays($request->check_out);

        // Attribute public bookings to a stable system user (self-seeding) — never a hardcoded
        // id, so a missing/renumbered user 1 can't 500 the public booking funnel.
        // withTrashed(): if the system user was SOFT-DELETED (e.g. removed from the admin Users
        // screen), a plain firstOrCreate can't see the trashed row but the users.email UNIQUE
        // index still counts it — so it would try to re-INSERT and 500 with a duplicate-key on
        // EVERY public booking. Looking up including trashed rows finds it; restore() un-trashes
        // it so the funnel self-heals instead of going down.
        $creator = User::systemForCurrentTenant();

        try {
            // Lock the room row + re-check availability INSIDE the transaction so two
            // concurrent bookings for the same room can't both pass the check (no double-book).
            $reservation = DB::transaction(function () use ($request, $room, $creator) {
                Room::where('id', $room->id)->lockForUpdate()->first();

                if (! Reservation::isRoomAvailable($room->id, $request->check_in, $request->check_out)) {
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

                // Fill-if-EMPTY only: an existing guest's saved values are never overwritten
                // (anti-tamper above), but blanks may be completed from this submission — so
                // a repeat booker's nationality/phone still reach the card form's pre-fill.
                if (! $guest->wasRecentlyCreated) {
                    if (! $guest->nationality && $request->filled('nationality')) {
                        $guest->nationality = $request->nationality;
                    }
                    if (! $guest->phone && $request->filled('phone')) {
                        $guest->phone = $request->phone;
                    }
                    if ($guest->isDirty()) {
                        $guest->save();
                    }
                }

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
                    'channel' => 'direct',
                    'created_via' => Reservation::CREATED_VIA_WEBSITE,
                    'created_by' => $creator->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            // Validation error (not a flash) → the wizard keeps every typed field and shows
            // an in-step recovery banner instead of resetting the guest to step 1.
            throw ValidationException::withMessages([
                'room_id' => 'Kjo dhomë nuk është më e disponueshme për këto data — zgjidh një dhomë tjetër.',
            ]);
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
                    // Return to the payment page — it re-verifies with POK and forwards a paid
                    // booking to confirmation (works for BOTH the embedded flow and the hosted-page fallback).
                    'redirect' => route('website.pay.show', $reservation->confirmation_token),
                    'fail' => route('website.pay.show', $reservation->confirmation_token),
                    'expires' => 30,
                ]);
                $reservation->update(['pok_order_id' => $order['id']]);

                return redirect()->route('website.pay.show', $reservation->confirmation_token)
                    ->with('book_guest_name', $guestName);
            } catch (\Throwable $e) {
                report($e);
                // Couldn't reach POK — release the just-held room and ask the guest to retry.
                // Validation error (not a flash) so the wizard keeps everything typed.
                $reservation->update(['status' => 'cancelled']);

                throw ValidationException::withMessages([
                    'room_id' => 'Nuk u lidh dot pagesa me kartë. Provo sërish pas pak.',
                ]);
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
            ->with(['room.roomType', 'guest'])
            ->firstOrFail();

        // No order / already resolved → the confirmation page reflects the true state.
        if ($reservation->status !== 'pending' || ! $reservation->pok_order_id) {
            return redirect()->route('website.booking.confirmation', $token);
        }

        // POK captures the moment the card form succeeds, so the guest may ALREADY have paid
        // (a lost confirm POST, or they navigated back here). Re-verify BEFORE re-mounting a live
        // card form — otherwise a paid guest is shown "pay again" (confusing / double-charge risk).
        try {
            if (app(PokPayments::class)->settle($reservation)) {
                return redirect()->route('website.booking.confirmation', $token);
            }
        } catch (\Throwable $e) {
            report($e);

            // POK unreachable — show a neutral "confirming your payment" state, NOT a live form.
            return Inertia::render('Website/BookingPayment', array_merge($this->paymentProps($reservation, $token), [
                'openForPayment' => false,
            ]));
        }

        // Order genuinely still open/unpaid → render the embedded card form.
        return Inertia::render('Website/BookingPayment', array_merge($this->paymentProps($reservation, $token), [
            'openForPayment' => true,
        ]));
    }

    /** @return array<string,mixed> */
    private function paymentProps(Reservation $reservation, string $token): array
    {
        return [
            'orderId' => $reservation->pok_order_id,
            'env' => app(PokConfiguration::class)->get('production', false) ? 'production' : 'staging',
            'amount' => (float) $reservation->total_amount,
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
            'guestName' => session('book_guest_name'),
            'confirmUrl' => route('website.pay.confirm', $token),
            // Pre-fill POK's card form from the PERSISTED guest (survives refresh + POK round-trip),
            // so the guest re-enters ONLY the card number/expiry/CVC — never email/name/country/phone
            // they already typed at booking. array_filter drops nulls so only real values reach POK.
            // countryCode must be ISO alpha-2 (Book.vue's country <select> already stores alpha-2);
            // legacy/empty values fall through to no preset rather than an invalid one.
            'initialState' => $this->pokInitialState($reservation->guest),
            // POK's own hosted card page for this order — a silent fallback the SDK onError path
            // redirects to (no longer a visible competing button). The guest pays here and POK
            // returns them to pay.show.
            'payUrl' => rtrim(app(PokConfiguration::class)->payUrl(), '/').'/sdk-orders/'.$reservation->pok_order_id,
            'roomName' => $reservation->room?->roomType?->name,
            'nights' => (int) now()->parse($reservation->check_in_date)->diffInDays($reservation->check_out_date),
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            // The POK order expires 30 min after creation (createOrder 'expires' => 30) and the
            // release cron frees unpaid holds — show the guest the same clock that's running.
            'holdExpiresAt' => $reservation->created_at?->copy()->addMinutes(30)->toIso8601String(),
        ];
    }

    /**
     * POK renderForm initialState from the guest — pre-fills the identity fields so the card form
     * asks only for the card itself. Never pre-fill card data. Only a valid ISO alpha-2 nationality
     * becomes countryCode; anything else is omitted (empty POK country dropdown, not a wrong preset).
     *
     * @return array<string,string>
     */
    private function pokInitialState(?Guest $guest): array
    {
        if (! $guest) {
            return [];
        }

        $country = preg_match('/^[A-Za-z]{2}$/', (string) $guest->nationality)
            ? strtoupper($guest->nationality)
            : null;

        return array_filter([
            'email' => $guest->email ?: null,
            'holdersName' => trim("{$guest->first_name} {$guest->last_name}") ?: null,
            'countryCode' => $country,
            'phoneNumber' => $guest->phone ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Browser calls this after the embedded form fires onSuccess — verify + confirm. */
    public function confirmPayment(string $token): RedirectResponse
    {
        $reservation = Reservation::where('confirmation_token', $token)->firstOrFail();

        try {
            app(PokPayments::class)->settle($reservation);
        } catch (\Throwable $e) {
            report($e); // never 500 the guest — the webhook is the backstop
        }

        if ($reservation->fresh()->status === 'confirmed') {
            return redirect()->route('website.booking.confirmation', $token);
        }

        return redirect()->route('website.pay.show', $token)
            ->with('error', "Pagesa s'u konfirmua ende. Nëse e paguat, prit pak sekonda dhe rifresko.");
    }

    /** POK server-to-server webhook (CSRF-exempt). Never trusts the body — re-verifies via getOrder. */
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
                    app(PokPayments::class)->settle($reservation); // confirms a payment OR reverses a refund
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        // POK retries on non-2xx — always acknowledge.
        return response('ok', 200);
    }

    public function bookingConfirmation(string $token): Response|RedirectResponse
    {
        // Look up by the unguessable token, never by the sequential id (IDOR-safe).
        $reservation = Reservation::where('confirmation_token', $token)
            ->with(['room.roomType', 'guest'])
            ->firstOrFail();

        // Payment not finished yet (pending with a POK order) → send the guest back to pay,
        // never show a "booked successfully" screen for an unpaid hold.
        if ($reservation->status === 'pending' && $reservation->pok_order_id) {
            return redirect()->route('website.pay.show', $token);
        }

        // Pass ONLY the fields this page renders — never the full Guest model
        // (document_number, date_of_birth, etc. must not reach the public props).
        return Inertia::render('Website/BookingConfirmation', [
            'status' => $reservation->status, // 'confirmed' (paid) | 'pending' (no online payment) | 'cancelled'
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
        Log::info('Contact form submission', $request->only('name', 'email', 'message'));

        return back()->with('success', 'Faleminderit! Mesazhi juaj u derua me sukses.');
    }
}
