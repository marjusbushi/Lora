<?php

namespace App\Models;

/** Owner intent to keep a date on its normal rate, without a fake override. */
class PricingManualProtection extends TenantModel
{
    protected $fillable = ['room_type_id', 'date', 'created_by', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
