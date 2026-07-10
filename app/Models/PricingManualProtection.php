<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner intent to keep a date on its normal rate, without a fake override. */
class PricingManualProtection extends Model
{
    protected $fillable = ['room_type_id', 'date', 'created_by', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
