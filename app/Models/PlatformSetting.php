<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide setting — shared by every hotel, managed from the super-admin
 * control panel. NOT tenant-scoped on purpose (plain Model, not TenantModel);
 * never store per-hotel data here.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'type'];

    /**
     * Get a platform setting value by dotted key (e.g. "currencies.rates").
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return self::castValue($setting->value, $setting->type);
    }

    /**
     * Set a platform setting value by dotted key.
     */
    public static function set(string $key, mixed $value, string $type = 'text'): void
    {
        $storedValue = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $storedValue, 'type' => $type]
        );
    }

    private static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }
}
