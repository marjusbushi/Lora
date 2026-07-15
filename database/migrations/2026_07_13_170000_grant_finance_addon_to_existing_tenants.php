<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Finance becomes a PAID add-on (29 €/muaj). Existing tenants (Villa
     * Mucho) are grandfathered so nothing turns off on deploy — new tenants
     * start WITHOUT it until the platform admin grants it.
     */
    public function up(): void
    {
        Tenant::query()->each(function (Tenant $tenant) {
            $meta = $tenant->metadata ?? [];
            $meta['addons'] = array_values(array_unique([...($meta['addons'] ?? []), 'finance']));
            $tenant->timestamps = false;
            $tenant->update(['metadata' => $meta]);
        });
    }

    public function down(): void
    {
        // Finance was introduced by this migration, so remove only that grant
        // and preserve every other add-on and metadata value.
        Tenant::query()->each(function (Tenant $tenant) {
            $meta = $tenant->metadata ?? [];
            $addons = is_array($meta['addons'] ?? null) ? $meta['addons'] : [];
            $addons = array_values(array_filter(
                $addons,
                fn (mixed $addon): bool => $addon !== 'finance',
            ));

            if ($addons === []) {
                unset($meta['addons']);
            } else {
                $meta['addons'] = $addons;
            }

            $tenant->timestamps = false;
            $tenant->update(['metadata' => $meta === [] ? null : $meta]);
        });
    }
};
