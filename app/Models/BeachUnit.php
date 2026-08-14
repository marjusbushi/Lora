<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BeachUnit extends TenantModel
{
    protected $fillable = ['beach_zone_id', 'number', 'qr_token', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $unit) {
            if (blank($unit->qr_token)) {
                $unit->qr_token = Str::random(40);
            }
        });
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(BeachZone::class, 'beach_zone_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BeachReservation::class);
    }
}
