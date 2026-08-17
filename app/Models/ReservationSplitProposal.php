<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;

/**
 * A desk-mediated offer to split one stay across rooms of the SAME type when
 * nightly inventory covers the booking but no single room can (guests already
 * checked in anchor the calendar). Born at import time; the desk talks to the
 * guest and records the outcome. Lora never cancels a reservation over this —
 * a refusal is handled by staff through Booking.com and only RECORDED here.
 */
class ReservationSplitProposal extends TenantModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const OUTCOMES = ['accepted', 'declined_upgraded', 'declined_escalated'];

    protected $fillable = [
        'reservation_id',
        'segments',
        'status',
        'outcome',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The global banner reads a cached per-tenant pending count on EVERY page
     * load — model writes invalidate it here so the middleware never re-counts.
     * Writers must go through the model (not the query builder) or the banner
     * goes stale.
     */
    protected static function booted(): void
    {
        $forget = fn (self $proposal) => Cache::forget(self::pendingCountCacheKey((int) $proposal->tenant_id));
        static::saved($forget);
        static::deleted($forget);
    }

    public static function pendingCountCacheKey(int $tenantId): string
    {
        return "split_proposals_pending:{$tenantId}";
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
