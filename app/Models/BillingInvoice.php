<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingInvoice extends Model
{
    protected $fillable = [
        'tenant_id', 'tenant_subscription_id', 'number', 'status', 'currency',
        'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents',
        'amount_paid_cents', 'period_starts_on', 'period_ends_on', 'issued_at',
        'due_on', 'paid_at', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'issued_at' => 'datetime',
            'due_on' => 'date',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillingInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function getBalanceCentsAttribute(): int
    {
        return max(0, $this->total_cents - $this->amount_paid_cents);
    }
}
