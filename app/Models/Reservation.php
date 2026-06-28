<?php

namespace App\Models;

use App\Observers\ReservationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[ObservedBy([ReservationObserver::class])]
class Reservation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Booking channels (source of a reservation). Keep in sync with the frontend
     * resources/js/channels.js. Lowercase/dotted ids match the channel-manager
     * vocabulary (booking.com, airbnb, …). 'manual' = entered by staff, 'direct'
     * = booked on villamucho.com.
     */
    public const CHANNELS = [
        'manual', 'direct', 'booking.com', 'expedia', 'airbnb', 'agoda',
        'hotels.com', 'vrbo', 'trip.com', 'hostelworld', 'google', 'tripadvisor',
    ];

    protected $fillable = [
        'room_id',
        'guest_id',
        'created_by',
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
        'commission_amount',
    ];

    /**
     * Auto-assign an unguessable confirmation token on creation (admin + website),
     * so the public confirmation page is never reachable by enumerating the id.
     */
    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation) {
            if (empty($reservation->confirmation_token)) {
                $reservation->confirmation_token = (string) Str::random(40);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'total_amount' => 'decimal:2',
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

        return !$query->exists();
    }
}
