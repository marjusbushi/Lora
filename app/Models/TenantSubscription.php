<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'status',
        'billing_cycle',
        'billing_anchor_day',
        'currency',
        'annual_discount_percent',
        'starts_at',
        'trial_ends_at',
        'current_period_ends_at',
        'next_billing_at',
        'last_billed_at',
        'cancels_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'annual_discount_percent' => 'integer',
            'billing_anchor_day' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'next_billing_at' => 'datetime',
            'last_billed_at' => 'datetime',
            'cancels_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }
}
