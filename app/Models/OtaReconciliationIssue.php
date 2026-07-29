<?php

namespace App\Models;

class OtaReconciliationIssue extends TenantModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'reservation_id',
        'channel',
        'external_ref',
        'channex_booking_id',
        'issue_type',
        'severity',
        'status',
        'expected_total',
        'actual_total',
        'currency',
        'details',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_total' => 'decimal:2',
            'actual_total' => 'decimal:2',
            'details' => 'array',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
