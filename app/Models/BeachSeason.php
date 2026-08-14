<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class BeachSeason extends TenantModel
{
    protected $fillable = ['name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        // 'date:Y-m-d' — JSON-i del pa orë/UTC, ndryshe data-only zhvendoset
        // një ditë pas në frontend (mësimi i njohur i kalendarit të dhomave).
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(BeachSeasonPrice::class);
    }
}
