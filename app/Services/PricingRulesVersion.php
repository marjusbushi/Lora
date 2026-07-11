<?php

namespace App\Services;

use App\Models\Setting;
use App\Tenancy\TenantContext;

/** DB-backed mutex/version for every persisted input that changes engine output. */
class PricingRulesVersion
{
    public static function current(): int
    {
        return (int) Setting::get('pricing.rules_version', 0);
    }

    /** Call only inside a database transaction. */
    public static function lock(): Setting
    {
        $query = Setting::query()
            ->where('group', 'pricing')
            ->where('key', 'rules_version');

        $version = (clone $query)->lockForUpdate()->first();
        if ($version) {
            return $version;
        }

        // Self-heal older or partially migrated installations. The unique
        // (group, key) index makes this safe when two requests race to create it.
        Setting::query()->insertOrIgnore([
            'tenant_id' => app(TenantContext::class)->idOrDefault(),
            'group' => 'pricing',
            'key' => 'rules_version',
            'value' => '0',
            'type' => 'number',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = (clone $query)->lockForUpdate()->first();
        if (! $version) {
            throw new \RuntimeException('Pricing rules version could not be initialized.');
        }

        return $version;
    }

    /** Increment a row returned by lock(). */
    public static function increment(Setting $version): void
    {
        $version->update([
            'value' => (string) ((int) $version->value + 1),
            'type' => 'number',
        ]);
    }
}
