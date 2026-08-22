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
        'resolution',
        'resolved_by',
        'resolution_fingerprint',
    ];

    /** Staff said: the guest extended/changed at the desk — not a channel error. */
    public const RESOLUTION_EXTENDED_ON_DESK = 'extended_on_desk';

    /** Issue families a desk explanation may close. */
    public const DESK_RESOLVABLE_TYPES = ['amount_mismatch', 'stay_mismatch'];

    /**
     * What the PMS side looked like when staff closed the card. The nightly
     * checker compares against the CURRENT PMS side: same fingerprint → stays
     * closed; different → the reservation changed again, reopen.
     */
    public static function fingerprint(?float $actualTotal, ?array $details): string
    {
        return sha1(json_encode([
            round((float) $actualTotal, 2),
            $details['local_stays'] ?? null,
        ]));
    }

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
