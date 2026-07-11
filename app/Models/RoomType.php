<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'base_price',
        'min_price',
        'max_price',
        'max_occupancy',
        'amenities',
        'breakfast_included',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'amenities' => 'array',
            'breakfast_included' => 'boolean',
        ];
    }

    /**
     * The owner's price guardrails, normalized: an inverted pair (min > max)
     * is misconfiguration — treat it as unset so the engine's clamp and the
     * apply guard fall back to the same 0.25x-4x base sanity band and can
     * never disagree with each other.
     *
     * @return array{0: ?float, 1: ?float} [min, max]
     */
    public function priceBounds(): array
    {
        $min = $this->min_price !== null ? (float) $this->min_price : null;
        $max = $this->max_price !== null ? (float) $this->max_price : null;
        if ($min !== null && $max !== null && $min > $max) {
            return [null, null];
        }

        return [$min, $max];
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function images()
    {
        return $this->hasMany(RoomTypeImage::class)->orderBy('sort_order');
    }

    public function featuredImage()
    {
        return $this->hasOne(RoomTypeImage::class)->orderBy('sort_order');
    }
}
