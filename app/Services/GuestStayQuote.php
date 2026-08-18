<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\RoomType;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * One shared resolver for "what is free and what does it cost for this stay" —
 * used by BOTH the staff-facing MCP CheckAvailabilityTool and Lora's guest chat
 * (GenerateAiGuestReply). Availability math lives here once; prices always come
 * from the canonical engine (RoomPricing), never re-computed by callers.
 *
 * Guest-facing prices are CHANNEL-AWARE (vendim i Marjusit, 2026-08-18):
 * - whatsapp/direct → DirectBookingPricing (finali PAS zbritjes direkte),
 * - bisedë OTA → çmimi kanonik PA zbritje direkte. Kanonik = finali që mysafiri
 *   sheh në OTA pas programeve Genius/Member (OtaPricingPrograms e mban BAR-in
 *   e publikuar më lart pikërisht që zbritja e OTA-s të zbresë te ky çmim) —
 *   pra kurrë s'i tregojmë mysafirit të Booking-ut çmimin tonë direkt me ulje.
 */
class GuestStayQuote
{
    public const MAX_NIGHTS = 31;

    public function __construct(private DirectBookingPricing $directPricing)
    {
    }

    /**
     * Availability + canonical quote per room type — the raw rows both surfaces map from.
     *
     * @return array<int,array{type:RoomType,booked:int,available:int,quote:array}>
     */
    public function rows(Carbon $from, Carbon $to, int $adults = 1): array
    {
        if ($to->lte($from)) {
            throw new InvalidArgumentException('Data e largimit duhet të jetë pas datës së mbërritjes.');
        }
        if ($from->diffInDays($to) > self::MAX_NIGHTS) {
            throw new InvalidArgumentException('Qëndrimi maksimal që mund të llogaritet është '.self::MAX_NIGHTS.' net.');
        }

        $types = RoomType::withCount(['rooms' => fn ($q) => $q->where('status', '!=', 'maintenance')])
            ->where('max_occupancy', '>=', $adults)->orderBy('name')->get();
        $booked = Reservation::query()->whereNotIn('reservations.status', ['cancelled', 'checked_out'])
            ->whereDate('reservations.check_in_date', '<', $to->toDateString())
            ->whereDate('reservations.check_out_date', '>', $from->toDateString())
            ->join('rooms', 'reservations.room_id', '=', 'rooms.id')
            ->selectRaw('rooms.room_type_id, count(distinct reservations.room_id) as booked')
            ->groupBy('rooms.room_type_id')->pluck('booked', 'rooms.room_type_id');
        $quotes = RoomPricing::quoteMany($types, $from, $to);

        return $types->map(fn (RoomType $type) => [
            'type' => $type,
            'booked' => (int) ($booked[$type->id] ?? 0),
            'available' => max(0, $type->rooms_count - (int) ($booked[$type->id] ?? 0)),
            'quote' => $quotes[$type->id],
        ])->values()->all();
    }

    /**
     * Channel-aware quote for a GUEST conversation — the exact numbers Lora is
     * allowed to say. Compact keys on purpose: this array is fed verbatim to the
     * AI as a tool result, and the rule is "quote these numbers, never compute".
     *
     * @return array{currency:string,check_in:string,check_out:string,nights:int,adults:int,room_types:array<int,array<string,mixed>>}
     */
    public function forGuest(string $channel, string $checkIn, string $checkOut, int $adults = 1): array
    {
        $from = Carbon::parse($checkIn);
        $to = Carbon::parse($checkOut);
        $direct = $channel === 'whatsapp';

        $roomTypes = collect($this->rows($from, $to, max(1, min(20, $adults))))
            ->map(function (array $row) use ($direct) {
                $priced = $this->directPricing->applyTo($row['quote']);
                $base = [
                    'name' => $row['type']->name,
                    'max_occupancy' => (int) $row['type']->max_occupancy,
                    'rooms_available' => $row['available'],
                    'breakfast_included' => (bool) $row['type']->breakfast_included,
                ];

                if (! $direct || $priced['discount_pct'] <= 0) {
                    // Bisedë OTA (ose pa zbritje aktive): kanoniku është finali.
                    $nights = (int) $row['quote']['nights'];
                    $total = round((float) $row['quote']['total'], 2);

                    return $base + [
                        'stay_total' => $total,
                        'price_per_night' => $nights > 0 ? round($total / $nights, 2) : 0.0,
                    ];
                }

                return $base + [
                    'stay_total' => $priced['total'],
                    'price_per_night' => $priced['price_per_night'],
                    'price_before_direct_discount' => $priced['original_total'],
                    'direct_discount_pct' => $priced['discount_pct'],
                ];
            })->values()->all();

        return [
            'currency' => PricingCurrency::code(),
            'check_in' => $from->toDateString(),
            'check_out' => $to->toDateString(),
            'nights' => $from->diffInDays($to),
            'adults' => $adults,
            'room_types' => $roomTypes,
        ];
    }
}
