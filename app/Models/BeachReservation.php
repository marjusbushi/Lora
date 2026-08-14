<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeachReservation extends TenantModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
    ];

    public const SOURCE_WEBSITE = 'website';

    public const SOURCE_RECEPTION = 'reception';

    protected $fillable = [
        'beach_unit_id', 'reservation_id', 'guest_name', 'guest_phone', 'guest_email',
        'start_date', 'end_date', 'status', 'source', 'total_amount',
        'confirmation_token', 'paid_at', 'pok_order_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Datat janë INKLUZIVE (ditë plazhi, jo netë): 15–17 mbivendoset me 17–19.
     */
    public static function isUnitAvailable(
        int $unitId,
        string $startDate,
        string $endDate,
        ?int $excludeId = null,
    ): bool {
        return ! static::query()
            ->where('beach_unit_id', $unitId)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();
    }

    public function totalDays(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BeachUnit::class, 'beach_unit_id');
    }

    public function roomReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
