<?php

namespace App\Services;

use App\Models\PricingOffer;
use App\Models\Setting;
use App\Tenancy\TenantContext;

/**
 * Keeps the hotel's desired guest-facing price canonical while calculating
 * the higher BAR each OTA needs before its private/member promotions.
 *
 * These values are intentionally presentation/configuration data. Channex's
 * per-channel modifier is the safe place to apply the markup; changing the
 * canonical PMS rate would also inflate the hotel website and every OTA.
 *
 * Time-boxed OTA offers (Renato 2026-08-19) compose here too: the campaign
 * lives in the OTA's extranet, Lora compensates the pushed price for the
 * offer's channel and dates — factorFor(channel, date) = program factor ×
 * (1 − deepest active offer). Overlapping same-channel offers never stack.
 */
class OtaPricingPrograms
{
    private const CHANNELS = ['booking.com', 'expedia', 'airbnb'];

    /**
     * The combined divisor never drops below this once an offer joins in —
     * a typo like 90% would otherwise multiply the pushed price tenfold.
     */
    private const OFFER_FACTOR_FLOOR = 0.3;

    /** @var array<int, \Illuminate\Support\Collection<int, PricingOffer>> */
    private static array $offerCache = [];

    public static function settings(): array
    {
        $bookingDiscounts = array_values(array_filter([
            self::discount('Genius', 'booking_genius'),
            self::discount('Mobile Price', 'booking_mobile'),
        ]));
        $expediaDiscounts = array_values(array_filter([
            self::discount('Member Price', 'expedia_member'),
            self::discount('Mobile Price', 'expedia_mobile'),
        ]));
        // Airbnb's "program" is its host fee: deducted from the payout rather
        // than shown to the guest, but the compensation math is identical.
        $airbnbDiscounts = array_values(array_filter([
            self::discount('Host fee', 'airbnb_host_fee', 15),
        ]));

        return [
            'booking' => self::channelSummary(
                'booking.com',
                $bookingDiscounts,
                (bool) Setting::get('pricing_programs.booking_preferred_enabled', false),
            ),
            'expedia' => self::channelSummary('expedia', $expediaDiscounts, false),
            'airbnb' => self::channelSummary('airbnb', $airbnbDiscounts, false),
        ];
    }

    public static function quote(float $targetPrice, ?string $date = null): array
    {
        $targetPrice = round(max(0, $targetPrice), 2);

        return collect(self::settings())->map(function (array $channel, string $key) use ($targetPrice, $date) {
            $factor = $date !== null
                ? self::factorFor($key, $date)
                : (float) $channel['discount_factor'];
            $offerPct = $date !== null ? self::offerPct($key, $date) : 0.0;
            $published = $factor > 0
                ? round($targetPrice / $factor, 2)
                : $targetPrice;
            $net = round($targetPrice * (1 - $channel['commission_pct'] / 100), 2);

            return array_merge($channel, [
                'target_price' => $targetPrice,
                'published_price' => $published,
                'estimated_net' => $net,
                'offer_pct' => round($offerPct, 2),
            ]);
        })->all();
    }

    /** Add OTA economics to one Smart Pricing calendar/suggestion row. */
    public static function decorate(array $row): array
    {
        $row['ota_prices'] = self::quote((float) $row['suggested_price'], $row['date'] ?? null);

        return $row;
    }

    /**
     * The full divisor for one channel on one date: static program factor ×
     * the deepest active offer, floored so a fat-fingered discount can never
     * explode the pushed price.
     */
    public static function factorFor(string $channelKey, string $date): float
    {
        $base = (float) (self::settings()[$channelKey]['discount_factor'] ?? 1.0);
        $pct = self::offerPct($channelKey, $date);
        if ($pct <= 0) {
            return $base;
        }

        // Rounded like channelSummary's discount_factor, so equality holds.
        return max(self::OFFER_FACTOR_FLOOR, round($base * (1 - $pct / 100), 6));
    }

    /** Deepest active offer discount (percent) for a channel on a date. */
    public static function offerPct(string $channelKey, string $date): float
    {
        return (float) self::activeOffers()
            ->filter(fn (PricingOffer $offer) => $offer->channel === $channelKey
                && $offer->starts_on->toDateString() <= $date
                && $offer->ends_on->toDateString() >= $date)
            ->max('discount_pct');
    }

    /** Forget the per-request offer memo (offer writes and tests call this). */
    public static function flushOffers(): void
    {
        self::$offerCache = [];
    }

    /** @return \Illuminate\Support\Collection<int, PricingOffer> */
    private static function activeOffers(): \Illuminate\Support\Collection
    {
        $tenantId = app(TenantContext::class)->id() ?? 0;

        return self::$offerCache[$tenantId] ??= PricingOffer::query()
            ->where('active', true)
            ->get();
    }

    private static function discount(string $label, string $key, float $defaultPct = 10): ?array
    {
        if (! (bool) Setting::get("pricing_programs.{$key}_enabled", false)) {
            return null;
        }

        $pct = min(50.0, max(0.0, (float) Setting::get("pricing_programs.{$key}_pct", $defaultPct)));

        return ['key' => $key, 'label' => $label, 'pct' => round($pct, 2)];
    }

    private static function channelSummary(string $channel, array $discounts, bool $preferred): array
    {
        $factor = array_reduce(
            $discounts,
            fn (float $carry, array $discount) => $carry * (1 - $discount['pct'] / 100),
            1.0,
        );
        $factor = max(0.01, $factor);
        $fees = (array) Setting::get('financial.channel_fees', []);
        $commission = min(100.0, max(0.0, (float) ($fees[$channel] ?? 0)));

        return [
            'channel' => $channel,
            'discounts' => $discounts,
            'combined_discount_pct' => round((1 - $factor) * 100, 2),
            'discount_factor' => round($factor, 6),
            'required_modifier_pct' => round((1 / $factor - 1) * 100, 2),
            'commission_pct' => round($commission, 2),
            'preferred_partner' => $preferred,
        ];
    }
}
