<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\RoomPricing;
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
            'notes' => ['nullable', 'string', 'max:1000'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $room = Room::with('roomType')->findOrFail($request->room_id);

        if ($room->roomType && $request->adults > $room->roomType->max_occupancy) {
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

                // Match on normalized email; update name/phone on an existing guest
                // instead of discarding them (and don't create case/whitespace duplicates).
                $guest = Guest::updateOrCreate(
                    ['email' => strtolower(trim($request->email))],
                    [
                        'first_name' => $request->first_name,
                        'last_name' => $request->last_name,
                        'phone' => $request->phone,
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
                    'notes' => $request->notes,
                    'channel' => 'direct', // booked on villamucho.com
                    'created_by' => $creator->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Kjo dhome nuk eshte me e disponueshme per keto data.');
        }

        return redirect()->route('website.booking.confirmation', $reservation->confirmation_token);
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
                'guest_name' => $reservation->guest?->full_name,
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
