<?php

namespace App\Models;

use App\Support\TenantKey;
use Illuminate\Support\Facades\Cache;

class Setting extends TenantModel
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    /**
     * Get a setting value by "group.key" notation.
     */
    public static function get(string $path, mixed $default = null): mixed
    {
        [$group, $key] = explode('.', $path, 2);

        $setting = static::where('group', $group)->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return self::castValue($setting->value, $setting->type);
    }

    /**
     * Set a setting value by "group.key" notation.
     */
    public static function set(string $path, mixed $value, string $type = 'text'): void
    {
        [$group, $key] = explode('.', $path, 2);

        $storedValue = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $storedValue, 'type' => $type]
        );

        Cache::forget(self::cacheKey());
    }

    /**
     * Get all settings for a group as key => value array.
     */
    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->mapWithKeys(fn ($s) => [$s->key => self::castValue($s->value, $s->type)])
            ->toArray();
    }

    /**
     * Get all settings grouped by group name.
     */
    public static function allGrouped(): array
    {
        return static::all()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(fn ($s) => [$s->key => self::castValue($s->value, $s->type)]))
            ->toArray();
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

    public static function cacheKey(): string
    {
        return TenantKey::make('app.settings');
    }
}
