<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationStatusLog extends TenantModel
{
    /**
     * Append-only audit trail of reservation status transitions.
     * No updated_at: a log row is written once and never mutated.
     */
    public $timestamps = false;

    protected $fillable = [
        'reservation_id',
        'from_status',
        'to_status',
        'changed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
