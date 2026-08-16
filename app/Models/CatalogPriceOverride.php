<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override i çmimit të katalogut për një modul — PLATFORMË-GLOBAL.
 *
 * QËLLIMISHT extends Model (jo TenantModel): katalogu i çmimeve është një
 * për gjithë platformën dhe editohet vetëm nga super-admini; s'ka tenant_id.
 * Vetëm fushat jo-NULL mbivendosin config/lora_modules — struktura e modulit
 * (emri, përshkrimi, billing_model) mbetet gjithmonë në config.
 */
class CatalogPriceOverride extends Model
{
    protected $fillable = [
        'module_code',
        'unit_price_cents',
        'first_unit_price_cents',
        'excess_unit_price_cents',
        'tier_limit',
        'percentage_bps',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'first_unit_price_cents' => 'integer',
            'excess_unit_price_cents' => 'integer',
            'tier_limit' => 'integer',
            'percentage_bps' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
