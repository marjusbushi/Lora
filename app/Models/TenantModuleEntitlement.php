<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModuleEntitlement extends Model
{
    protected $fillable = [
        'tenant_id',
        'module_code',
        'enabled',
        'quantity',
        'unit_price_cents',
        'percentage_bps',
        'pricing_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'percentage_bps' => 'integer',
            'pricing_snapshot' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
