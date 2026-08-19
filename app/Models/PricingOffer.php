<?php

namespace App\Models;

use App\Jobs\PushRoomTypeAri;

/**
 * A time-boxed, per-channel OTA offer. The campaign itself lives in the OTA's
 * extranet — this record only makes Lora COMPENSATE the pushed price for the
 * channel and window (push = price ÷ (1 − discount)) so the hotel nets its
 * canonical price after the OTA applies the guest-visible discount.
 *
 * Overlapping offers on the same channel never stack: the deepest discount
 * wins for a date (see OtaPricingPrograms::offerPct).
 */
class PricingOffer extends TenantModel
{
    public const CHANNELS = ['booking', 'expedia', 'airbnb'];

    protected $fillable = [
        'name',
        'channel',
        'discount_pct',
        'starts_on',
        'ends_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'discount_pct' => 'float',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Any change to an offer re-pushes rates for the union of the old and
        // new windows, so the OTA prices follow immediately (no-op when
        // Channex is not configured — dispatchAllMapped guards that itself).
        static::saved(function (self $offer) {
            \App\Services\OtaPricingPrograms::flushOffers();
            $from = min($offer->starts_on, $offer->getOriginal('starts_on') ?? $offer->starts_on);
            $to = max($offer->ends_on, $offer->getOriginal('ends_on') ?? $offer->ends_on);
            PushRoomTypeAri::dispatchAllMapped($from, $to);
        });
        static::deleted(function (self $offer) {
            \App\Services\OtaPricingPrograms::flushOffers();
            PushRoomTypeAri::dispatchAllMapped($offer->starts_on, $offer->ends_on);
        });
    }
}
