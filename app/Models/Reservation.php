<?php

namespace App\Models;

use App\Observers\ReservationObserver;
use App\Services\MoneySnapshot;
use App\Services\PricingCurrency;
use App\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[ObservedBy([ReservationObserver::class])]
class Reservation extends TenantModel
{
    use HasFactory, SoftDeletes;

    /**
     * Commercial booking channels. How a reservation entered the PMS is stored
     * separately in created_via, so staff and website bookings both use "direct".
     * Keep in sync with resources/js/channels.js.
     */
    public const CHANNELS = [
        'direct', 'booking.com', 'expedia', 'airbnb', 'agoda',
        'hotels.com', 'vrbo', 'trip.com', 'hostelworld', 'google', 'tripadvisor',
    ];

    public const CREATED_VIA_STAFF = 'staff';

    public const CREATED_VIA_WEBSITE = 'website';

    public const CREATED_VIA_CHANNEL_MANAGER = 'channel_manager';

    public const CREATED_VIA_IMPORT = 'import';

    protected $fillable = [
        'room_id',
        'guest_id',
        'created_by',
        'created_via',
        'booked_at',
        'confirmation_token',
        'check_in_date',
        'check_out_date',
        'original_check_out_date',
        'status',
        'total_amount',
        'currency',
        'exchange_rate',
        'total_amount_base',
        'rate_before_discount',
        'rate_before_discount_base',
        'direct_discount_pct',
        'direct_discount_amount',
        'direct_discount_amount_base',
        'adults',
        'children',
        'notes',
        'channel',
        'channel_ref',
        'channex_booking_id',
        'booking_group_id',
        'commission_amount',
        'commission_amount_base',
        'payment_collect',
        'pok_order_id',
        'paid_at',
        'no_show_at',
        'no_show_by',
        'eta',
        'etd',
        'early_check_in',
        'late_check_out',
        'early_departure_original_room_total',
        'early_departure_scheduled_at',
        'early_departure_scheduled_by',
        'early_departure_at',
        'early_departure_by',
        'early_departure_policy',
        'early_departure_penalty_amount',
        'early_departure_reason',
    ];

    /**
     * Auto-assign an unguessable confirmation token on creation (admin + website),
     * so the public confirmation page is never reachable by enumerating the id.
     */
    protected static function booted(): void
    {
        static::saving(function (Reservation $reservation) {
            $reservation->channel = static::normalizeChannel($reservation->channel);

            $reservation->currency = strtoupper((string) ($reservation->currency ?: PricingCurrency::code()));
            if (! $reservation->exchange_rate) {
                $reservation->exchange_rate = MoneySnapshot::make(1, $reservation->currency)['exchange_rate'];
            }

            foreach ([
                'total_amount' => 'total_amount_base',
                'rate_before_discount' => 'rate_before_discount_base',
                'direct_discount_amount' => 'direct_discount_amount_base',
                'commission_amount' => 'commission_amount_base',
            ] as $source => $base) {
                if ($reservation->{$source} !== null && ($reservation->isDirty($source) || $reservation->{$base} === null)) {
                    $reservation->{$base} = round((float) $reservation->{$source} * (float) $reservation->exchange_rate, 2);
                }
            }
        });

        static::creating(function (Reservation $reservation) {
            if (empty($reservation->created_via)) {
                $reservation->created_via = static::CREATED_VIA_STAFF;
            }

            if (empty($reservation->booked_at)) {
                $reservation->booked_at = now();
            }

            if (empty($reservation->confirmation_token)) {
                $reservation->confirmation_token = (string) Str::random(40);
            }
        });
    }

    /** Map legacy staff/null channels to the single user-facing Direct channel. */
    public static function normalizeChannel(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return $channel === '' || $channel === 'manual' ? 'direct' : $channel;
    }

    /** Channels whose booking references are purely numeric on the OTA side. */
    public const NUMERIC_REF_CHANNELS = ['booking.com', 'expedia', 'agoda', 'hotels.com', 'trip.com'];

    /**
     * Validation rules for channel_ref given the (normalized) channel. For any
     * third-party source the OTA's reservation number is mandatory: cancellations
     * and modifications can only reach a reservation through it, and an unlinked
     * manual copy becomes invisible to the channel manager (Novotny/Morvan cases).
     * Uniqueness is per channel among non-cancelled rows, so a legitimate
     * re-entry after a cancel-and-replace still passes.
     */
    public static function channelRefRules(mixed $channel, ?int $ignoreReservationId = null): array
    {
        // A malformed channel (array, object) is rejected by the channel field's
        // own rule — stay lenient here so validation answers 422, not a 500.
        if (! is_string($channel) && $channel !== null) {
            return ['nullable', 'string', 'max:120'];
        }

        $channel = static::normalizeChannel($channel);
        if ($channel === 'direct') {
            return ['nullable', 'string', 'max:120'];
        }

        $unique = TenantRule::unique('reservations', 'channel_ref')
            ->where(fn ($query) => $query->where('channel', $channel)->where('status', '!=', 'cancelled'));
        if ($ignoreReservationId !== null) {
            $unique->ignore($ignoreReservationId);
        }

        return [
            'required',
            'string',
            in_array($channel, self::NUMERIC_REF_CHANNELS, true)
                ? 'regex:/^\d{6,15}$/'
                : 'regex:/^[A-Za-z0-9\-]{4,40}$/',
            $unique,
        ];
    }

    protected function casts(): array
    {
        return [
            // 'date:Y-m-d' serializes as a plain LOCAL date string ('2026-09-07'), not a
            // UTC ISO datetime. Without the format, a date stored '2026-09-07' becomes
            // '2026-09-06T22:00:00Z' (APP_TZ Europe/Tirane), and openEdit's .split('T')[0]
            // read '2026-09-06' — the edit form shifted every date one day back.
            'check_in_date' => 'date:Y-m-d',
            'check_out_date' => 'date:Y-m-d',
            'original_check_out_date' => 'date:Y-m-d',
            'booked_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'total_amount_base' => 'decimal:2',
            'rate_before_discount' => 'decimal:2',
            'rate_before_discount_base' => 'decimal:2',
            'direct_discount_pct' => 'decimal:2',
            'direct_discount_amount' => 'decimal:2',
            'direct_discount_amount_base' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_amount_base' => 'decimal:2',
            'paid_at' => 'datetime',
            'no_show_at' => 'datetime',
            'early_check_in' => 'boolean',
            'late_check_out' => 'boolean',
            'early_departure_original_room_total' => 'decimal:2',
            'early_departure_scheduled_at' => 'datetime',
            'early_departure_at' => 'datetime',
            'early_departure_penalty_amount' => 'decimal:2',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function earlyDepartureBy()
    {
        return $this->belongsTo(User::class, 'early_departure_by');
    }

    public function earlyDepartureScheduledBy()
    {
        return $this->belongsTo(User::class, 'early_departure_scheduled_by');
    }

    public function folioItems()
    {
        return $this->hasMany(FolioItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function fiscalDocuments()
    {
        return $this->hasMany(FiscalDocument::class);
    }

    public function getNightsAttribute(): int
    {
        return $this->check_in_date->diffInDays($this->check_out_date);
    }

    /**
     * Check if a room is available for the given date range, excluding this reservation.
     */
    public static function isRoomAvailable(int $roomId, string $checkIn, string $checkOut, ?int $excludeId = null): bool
    {
        // A room under maintenance is never bookable, regardless of date overlap.
        if (Room::whereKey($roomId)->where('status', 'maintenance')->exists()) {
            return false;
        }

        $query = static::where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return ! $query->exists();
    }
}
