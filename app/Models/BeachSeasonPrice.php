<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeachSeasonPrice extends TenantModel
{
    protected $fillable = ['beach_season_id', 'beach_zone_id', 'price_per_day'];

    protected function casts(): array
    {
        return [
            'price_per_day' => 'decimal:2',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(BeachSeason::class, 'beach_season_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(BeachZone::class, 'beach_zone_id');
    }
}
