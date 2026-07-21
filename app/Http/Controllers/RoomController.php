<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomStoreRequest;
use App\Http\Requests\RoomUpdateRequest;
use App\Models\Floor;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\ReservationMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /**
     * Room Rack frontend contract (each item in rooms.data):
     *
     * operational: {
     *   state: string,
     *   primary_action: { key, reservation_id, href, safe_inline, requires_settle_method },
     *   active_stay|arrival_today|departure_today|next_reservation: reservation|null,
     *   guest: { id, name, email, phone }|null,
     *   outstanding_balance|outstanding: float
     * }
     *
     * Without view_reservations, operational is reduced to non-sensitive room
     * state flags. guests/channelFees exist only with create_reservations.
     *
     * The paginator-shaped response intentionally contains every matching room;
     * the property is small and the Rooms page has no pagination controls.
     */
    public function index(Request $request): Response
    {
        $today = today()->toDateString();
        $canViewReservations = (bool) $request->user()?->can('view_reservations');
        $canCreateReservations = (bool) $request->user()?->can('create_reservations');
        $canViewGuests = (bool) $request->user()?->can('view_guests');
        $canCreateReservationFromRack = $canViewReservations
            && $canCreateReservations
            && $canViewGuests;
        $query = Room::select('id', 'room_type_id', 'room_number', 'floor', 'status', 'notes')
            ->with('roomType:id,name,base_price,max_occupancy')
            ->orderBy('room_number');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('floor')) {
            $query->where('floor', $request->floor);
        }

        if ($request->filled('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        $rooms = $query->get();
        $reservations = collect();
        $reservationsByRoom = collect();

        if ($rooms->isNotEmpty()) {
            $reservationColumns = ['id', 'room_id', 'check_in_date', 'check_out_date', 'status'];
            if ($canViewReservations) {
                $reservationColumns = array_merge($reservationColumns, [
                    'guest_id', 'total_amount', 'adults', 'children', 'channel', 'eta', 'etd',
                ]);
            }

            // One bounded eager-load for every displayed room: operational cards never
            // issue a query per room, guest, folio, or payment (no N+1).
            $reservationQuery = Reservation::query()
                ->select($reservationColumns)
                ->whereIn('room_id', $rooms->pluck('id'))
                ->where('status', '!=', 'cancelled')
                ->where(function ($reservationQuery) use ($today) {
                    $reservationQuery
                        ->where('status', 'checked_in')
                        ->orWhereDate('check_in_date', '>=', $today)
                        ->orWhereDate('check_out_date', $today);
                })
                ->orderBy('check_in_date')
                ->orderBy('id');

            if ($canViewReservations) {
                $reservationQuery->with([
                    'folioItems:id,reservation_id,amount,type',
                    'payments' => fn ($paymentQuery) => $paymentQuery
                        ->notVoided()
                        ->select('id', 'reservation_id', 'amount', 'is_voided'),
                ]);
            }

            $reservations = $reservationQuery->get();
        }

        // At most one guest query: creators need the modal's full list; viewers who
        // cannot create receive only guests attached to the displayed reservations.
        $guests = collect();
        if ($canCreateReservationFromRack) {
            $guests = Guest::select('id', 'first_name', 'last_name', 'email', 'phone')
                ->orderBy('last_name')
                ->get();
        } elseif ($canViewReservations && $reservations->isNotEmpty()) {
            $guestColumns = ['id', 'first_name', 'last_name'];
            if ($canViewGuests) {
                $guestColumns = array_merge($guestColumns, ['email', 'phone']);
            }

            $guests = Guest::select($guestColumns)
                ->whereIn('id', $reservations->pluck('guest_id')->filter()->unique())
                ->get();
        }

        if ($reservations->isNotEmpty()) {
            if ($canViewReservations) {
                // Reuse the authorized guest query for the operational summaries.
                // Users without view_reservations never get a guest relation attached.
                $guestsById = $guests->keyBy('id');
                $reservations->each(fn (Reservation $reservation) => $reservation->setRelation(
                    'guest',
                    $guestsById->get($reservation->guest_id),
                ));
            }

            $reservationsByRoom = $reservations->groupBy('room_id');
        }

        $roomRows = $rooms->map(function (Room $room) use (
            $reservationsByRoom,
            $today,
            $canViewReservations,
            $canCreateReservationFromRack,
            $canViewGuests,
        ) {
            return [
                'id' => $room->id,
                'room_type_id' => $room->room_type_id,
                'room_number' => $room->room_number,
                'floor' => $room->floor,
                'status' => $room->status,
                'notes' => $room->notes,
                'room_type' => $room->roomType,
                'operational' => $this->operationalPayload(
                    $room,
                    $reservationsByRoom->get($room->id, collect()),
                    $today,
                    $canViewReservations,
                    $canCreateReservationFromRack,
                    $canViewGuests,
                ),
            ];
        });

        if ($request->integer('room_id')) {
            $roomRows = $roomRows->where('id', $request->integer('room_id'))->values();
        }

        $search = Str::lower(mb_substr(trim((string) $request->input('search', '')), 0, 100));
        if ($search !== '') {
            $roomRows = $roomRows->filter(fn (array $room) => $this->matchesSearch($room, $search))->values();
        }

        $roomsPaginator = new LengthAwarePaginator(
            $roomRows,
            $roomRows->count(),
            max(1, $roomRows->count()),
            1,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $roomStats = Room::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'cleaning' THEN 1 ELSE 0 END) AS cleaning")
            ->selectRaw("SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance")
            ->first();

        $reservationStatsQuery = Reservation::query()
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN status = 'checked_in' THEN room_id END) AS occupied",
            );

        if ($canViewReservations) {
            $reservationStatsQuery
                ->selectRaw(
                    "COUNT(DISTINCT CASE WHEN check_in_date = ? AND status != 'cancelled' THEN room_id END) AS arrivals_today",
                    [$today],
                )
                ->selectRaw(
                    "COUNT(DISTINCT CASE WHEN check_out_date = ? AND status != 'cancelled' THEN room_id END) AS departures_today",
                    [$today],
                );
        }

        $reservationStats = $reservationStatsQuery->first();

        $stats = $canViewReservations
            ? [
                'total' => (int) ($roomStats->total ?? 0),
                'arrivals_today' => (int) ($reservationStats->arrivals_today ?? 0),
                'departures_today' => (int) ($reservationStats->departures_today ?? 0),
                'occupied' => (int) ($reservationStats->occupied ?? 0),
                'cleaning' => (int) ($roomStats->cleaning ?? 0),
                'maintenance' => (int) ($roomStats->maintenance ?? 0),
            ]
            : [
                'total' => (int) ($roomStats->total ?? 0),
                'occupied' => (int) ($reservationStats->occupied ?? 0),
                'cleaning' => (int) ($roomStats->cleaning ?? 0),
                'maintenance' => (int) ($roomStats->maintenance ?? 0),
            ];

        $props = [
            'rooms' => $roomsPaginator,
            'roomTypes' => RoomType::select('id', 'name', 'base_price', 'max_occupancy')->get(),
            'floors' => Floor::orderBy('number')->get(['number', 'name']),
            'filters' => $request->only('status', 'floor', 'room_type_id', 'search'),
            'stats' => $stats,
        ];

        if ($canCreateReservationFromRack) {
            $props['guests'] = $guests;
            $props['channelFees'] = Setting::get('financial.channel_fees', []);
        }

        return Inertia::render('Rooms/Index', $props);
    }

    /** @return array<string, mixed> */
    private function operationalPayload(
        Room $room,
        Collection $reservations,
        string $today,
        bool $canViewReservations,
        bool $canCreateReservationFromRack,
        bool $canViewGuests,
    ): array {
        $activeStay = $reservations
            ->where('status', 'checked_in')
            ->sortBy(fn (Reservation $reservation) => $reservation->check_out_date->toDateString().'-'.$reservation->id)
            ->first();

        $arrivalToday = $reservations
            ->filter(fn (Reservation $reservation) => $reservation->check_in_date->toDateString() === $today)
            ->sortBy(fn (Reservation $reservation) => sprintf(
                '%02d-%010d',
                ['checked_in' => 0, 'confirmed' => 1, 'pending' => 2, 'checked_out' => 3][$reservation->status] ?? 9,
                $reservation->id,
            ))
            ->first();

        $departureToday = $reservations
            ->filter(fn (Reservation $reservation) => $reservation->check_out_date->toDateString() === $today)
            ->sortBy(fn (Reservation $reservation) => sprintf(
                '%02d-%010d',
                ['checked_in' => 0, 'checked_out' => 1, 'confirmed' => 2, 'pending' => 3][$reservation->status] ?? 9,
                $reservation->id,
            ))
            ->first();

        $nextReservation = $reservations
            ->filter(fn (Reservation $reservation) => in_array($reservation->status, ['pending', 'confirmed'], true)
                && $reservation->check_in_date->toDateString() > $today)
            ->sortBy(fn (Reservation $reservation) => $reservation->check_in_date->toDateString().'-'.str_pad((string) $reservation->id, 10, '0', STR_PAD_LEFT))
            ->first();

        if (! $canViewReservations) {
            $occupied = (bool) $activeStay || $room->status === 'occupied';
            $state = match (true) {
                $room->status === 'maintenance' => 'maintenance',
                $occupied => 'occupied',
                $room->status === 'cleaning' => 'cleaning',
                default => 'vacant',
            };

            $payload = [
                'state' => $state,
                'occupancy_status' => $occupied ? 'occupied' : 'vacant',
                'housekeeping_status' => match ($room->status) {
                    'cleaning' => 'dirty',
                    'maintenance' => 'maintenance',
                    default => 'clean',
                },
                'service_status' => $room->status === 'maintenance' ? 'maintenance' : 'in_service',
                'out_of_order' => $room->status === 'maintenance',
            ];

            // A creator without reservation-view permission may start a booking only
            // when no current, same-day, or upcoming reservation blocks the room.
            if ($canCreateReservationFromRack
                && $state === 'vacant'
                && ! $arrivalToday
                && ! $nextReservation) {
                $payload['primary_action'] = $this->action('new_reservation');
            }

            return $payload;
        }

        $activePayload = $this->reservationPayload($activeStay, $canViewGuests);
        $arrivalPayload = $this->reservationPayload($arrivalToday, $canViewGuests);
        $departurePayload = $this->reservationPayload($departureToday, $canViewGuests);
        $nextPayload = $this->reservationPayload($nextReservation, $canViewGuests);
        $primary = $activePayload ?? $arrivalPayload ?? $departurePayload ?? $nextPayload;
        [$state, $primaryAction] = $this->operationalStateAndAction(
            $room,
            $activePayload,
            $arrivalPayload,
            $departurePayload,
            $nextPayload,
            $canCreateReservationFromRack,
        );

        return [
            'state' => $state,
            'primary_action' => $primaryAction,
            'occupancy_status' => $activePayload || $room->status === 'occupied' ? 'occupied' : 'vacant',
            'housekeeping_status' => match ($room->status) {
                'cleaning' => 'dirty',
                'maintenance' => 'maintenance',
                default => 'clean',
            },
            'service_status' => $room->status === 'maintenance' ? 'maintenance' : 'in_service',
            'out_of_order' => $room->status === 'maintenance',
            'active_stay' => $activePayload,
            'arrival_today' => $arrivalPayload,
            'departure_today' => $departurePayload,
            'next_reservation' => $nextPayload,
            'reservation_id' => $primary['id'] ?? null,
            'guest' => $primary['guest'] ?? null,
            'outstanding_balance' => (float) ($primary['outstanding_balance'] ?? 0),
            'outstanding' => (float) ($primary['outstanding_balance'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    private function reservationPayload(?Reservation $reservation, bool $canViewGuests): ?array
    {
        if (! $reservation) {
            return null;
        }

        $totals = ReservationMoney::totals($reservation);
        $roomCharge = $totals['room'];
        $outstanding = $totals['outstanding'];

        $guest = $reservation->guest ? [
            'id' => $reservation->guest->id,
            'name' => $reservation->guest->full_name,
        ] : null;

        if ($guest && $canViewGuests) {
            $guest['email'] = $reservation->guest->email;
            $guest['phone'] = $reservation->guest->phone;
        }

        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'check_in_date' => $reservation->check_in_date->toDateString(),
            'check_out_date' => $reservation->check_out_date->toDateString(),
            'eta' => $reservation->eta,
            'etd' => $reservation->etd,
            'adults' => (int) $reservation->adults,
            'children' => (int) ($reservation->children ?? 0),
            'channel' => $reservation->channel,
            'total_amount' => round($roomCharge, 2),
            'currency' => ReservationMoney::currency($reservation),
            'outstanding_balance' => $outstanding,
            'outstanding' => $outstanding,
            'guest' => $guest,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $active
     * @param  array<string, mixed>|null  $arrival
     * @param  array<string, mixed>|null  $departure
     * @param  array<string, mixed>|null  $next
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function operationalStateAndAction(
        Room $room,
        ?array $active,
        ?array $arrival,
        ?array $departure,
        ?array $next,
        bool $canCreateReservations,
    ): array {
        if ($room->status === 'maintenance') {
            return ['maintenance', $this->action('maintenance')];
        }

        if ($active) {
            if ($departure && $departure['id'] === $active['id']) {
                // Checkout may need settle_method, so the Room Rack opens the safe
                // checkout flow instead of performing a destructive one-click action.
                return ['departing_today', $this->action(
                    'check_out',
                    $active['id'],
                    false,
                    $active['outstanding_balance'] > 0.005,
                )];
            }

            return ['occupied', $this->action('view_stay', $active['id'])];
        }

        if ($room->status === 'cleaning') {
            return [
                $arrival ? 'cleaning_for_arrival' : 'cleaning',
                $this->action('housekeeping', $arrival['id'] ?? null),
            ];
        }

        if ($arrival && $arrival['status'] === 'confirmed') {
            return ['arrival_today', $this->action('check_in', $arrival['id'], true)];
        }

        if ($arrival) {
            return ['arrival_pending', $this->action('view_reservation', $arrival['id'])];
        }

        if ($departure) {
            return ['departed_today', $this->action('view_reservation', $departure['id'])];
        }

        if ($room->status === 'occupied') {
            return ['occupied_unlinked', $this->action('maintenance')];
        }

        if ($next) {
            return ['vacant_reserved', $this->action('view_reservation', $next['id'])];
        }

        return ['vacant', $canCreateReservations ? $this->action('new_reservation') : null];
    }

    /** @return array<string, mixed> */
    private function action(
        string $key,
        ?int $reservationId = null,
        bool $safeInline = false,
        bool $requiresSettleMethod = false,
    ): array {
        $href = match ($key) {
            'check_out', 'view_stay', 'view_reservation' => $reservationId
                ? route('reservations.show', $reservationId)
                : null,
            'check_in' => $reservationId ? route('reservations.check-in', $reservationId) : null,
            'housekeeping', 'maintenance' => route('housekeeping.index'),
            'new_reservation' => route('reservations.calendar'),
            default => null,
        };

        return [
            'key' => $key,
            'reservation_id' => $reservationId,
            'href' => $href,
            'safe_inline' => $safeInline,
            'requires_settle_method' => $requiresSettleMethod,
        ];
    }

    /** @param array<string, mixed> $room */
    private function matchesSearch(array $room, string $search): bool
    {
        if (Str::contains(Str::lower((string) $room['room_number']), $search)) {
            return true;
        }

        foreach (['active_stay', 'arrival_today', 'departure_today', 'next_reservation'] as $key) {
            $name = $room['operational'][$key]['guest']['name'] ?? '';
            if ($name !== '' && Str::contains(Str::lower($name), $search)) {
                return true;
            }
        }

        return false;
    }

    public function store(RoomStoreRequest $request): RedirectResponse
    {
        Room::create($request->validated());

        return back()->with('success', 'Dhoma u shtua me sukses.');
    }

    public function update(RoomUpdateRequest $request, Room $room): RedirectResponse
    {
        $data = $request->validated();

        if ($data['status'] === 'available' && $this->roomHasActiveStay($room)) {
            return back()->with('error', 'Dhoma ka nje mysafir brenda (check-in) — nuk mund te kalohet ne te lire.');
        }

        $room->update($data);

        return back()->with('success', 'Dhoma u perditesua me sukses.');
    }

    public function destroy(Room $room): RedirectResponse
    {
        if (! auth()->user()->can('delete_rooms')) {
            abort(403);
        }

        $room->delete();

        return back()->with('success', 'Dhoma u fshi.');
    }

    public function updateStatus(Request $request, Room $room): RedirectResponse
    {
        if (! auth()->user()->can('update_rooms')) {
            abort(403);
        }

        $request->validate([
            'status' => ['required', 'in:available,occupied,cleaning,maintenance'],
        ]);

        if ($request->status === 'available' && $this->roomHasActiveStay($room)) {
            return back()->with('error', 'Dhoma ka nje mysafir brenda (check-in) — nuk mund te kalohet ne te lire.');
        }

        $room->update(['status' => $request->status]);

        return back()->with('success', "Statusi u ndryshua ne {$request->status}.");
    }

    /**
     * Is this room currently occupied by a guest who has checked in (not yet out)?
     */
    private function roomHasActiveStay(Room $room): bool
    {
        return Reservation::where('room_id', $room->id)
            ->where('status', 'checked_in')
            ->exists();
    }
}
