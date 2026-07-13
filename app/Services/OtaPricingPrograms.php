<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Keeps the hotel's desired guest-facing price canonical while calculating
 * the higher BAR each OTA needs before its private/member promotions.
 *
 * These values are intentionally presentation/configuration data. Channex's
 * per-channel modifier is the safe place to apply the markup; changing the
 * canonical PMS rate would also inflate the hotel website and every OTA.
 */
class OtaPricingPrograms
{
    private const CHANNELS = ['booking.com', 'expedia'];

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

        return [
            'booking' => self::channelSummary(
                'booking.com',
                $bookingDiscounts,
                (bool) Setting::get('pricing_programs.booking_preferred_enabled', false),
            ),
            'expedia' => self::channelSummary('expedia', $expediaDiscounts, false),
        ];
    }

    public static function quote(float $targetPrice): array
    {
        $targetPrice = round(max(0, $targetPrice), 2);

        return collect(self::settings())->map(function (array $channel) use ($targetPrice) {
            $published = $channel['discount_factor'] > 0
                ? round($targetPrice / $channel['discount_factor'], 2)
                : $targetPrice;
            $net = round($targetPrice * (1 - $channel['commission_pct'] / 100), 2);

            return array_merge($channel, [
                'target_price' => $targetPrice,
                'published_price' => $published,
                'estimated_net' => $net,
            ]);
        })->all();
    }

    /** Add OTA economics to one Smart Pricing calendar/suggestion row. */
    public static function decorate(array $row): array
    {
        $row['ota_prices'] = self::quote((float) $row['suggested_price']);

        return $row;
    }

    private static function discount(string $label, string $key): ?array
    {
        if (! (bool) Setting::get("pricing_programs.{$key}_enabled", false)) {
            return null;
        }

        $pct = min(50.0, max(0.0, (float) Setting::get("pricing_programs.{$key}_pct", 10)));

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
