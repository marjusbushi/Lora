<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-currency drawer line of a POS shift: the foreign cash declared at
 * opening, what the tenders say should be there, and what was counted at
 * close. The base currency stays on pos_shifts itself (opening_float,
 * expected_cash, counted_cash) — rows here are foreign currencies only.
 */
class PosShiftCurrency extends TenantModel
{
    protected $fillable = [
        'pos_shift_id',
        'currency',
        'opening_amount',
        'expected_amount',
        'counted_amount',
        'over_short',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'over_short' => 'decimal:2',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }
}
