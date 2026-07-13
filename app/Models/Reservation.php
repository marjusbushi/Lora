<?php

namespace App\Models;

use App\Observers\ReservationObserver;
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
        'confirmation_token',
        'check_in_date',
        'check_out_date',
        'status',
        'total_amount',
        'adults',
        'children',
        'notes',
        'channel',
        'channel_ref',
        'channex_booking_id',
        'booking_group_id',
        'commission_amount',
        'payment_collect',
        'pok_order_id',
        'paid_at',
        'no_show_at',
        'no_show_by',
        'eta',
        'etd',
        'early_check_in',
        'late_check_out',
    ];

    /**
     * Auto-assign an unguessable confirmation token on creation (admin + website),
     * so the public confirmation page is never reachable by enumerating the id.
     */
    protected static function booted(): void
    {
        static::saving(function (Reservation $reservation) {
            $reservation->channel = static::normalizeChannel($reservation->channel);
        });

        static::creating(function (Reservation $reservation) {
            if (empty($reservation->created_via)) {
                $reservation->created_via = static::CREATED_VIA_STAFF;
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

    protected function casts(): array
    {
        return [
            // 'date:Y-m-d' serializes as a plain LOCAL date string ('2026-09-07'), not a
            // UTC ISO datetime. Without the format, a date stored '2026-09-07' becomes
            // '2026-09-06T22:00:00Z' (APP_TZ Europe/Tirane), and openEdit's .split('T')[0]
            // read '2026-09-06' — the edit form shifted every date one day back.
            'check_in_date' => 'date:Y-m-d',
            'check_out_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'no_show_at' => 'datetime',
            'early_check_in' => 'boolean',
            'late_check_out' => 'boolean',
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

    public function folioItems()
    {
        return $this->hasMany(FolioItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
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
