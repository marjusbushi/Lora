<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPaymentAttempt extends Model
{
    protected $fillable = [
        'tenant_id', 'tenant_subscription_id', 'billing_invoice_id', 'billing_payment_id',
        'provider', 'provider_attempt_id', 'status', 'currency', 'amount_cents',
        'attempt_number', 'failure_code', 'failure_message', 'attempted_at',
        'resolved_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'attempt_number' => 'integer',
            'attempted_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'billing_payment_id');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'billing_payment_attempt_id');
    }
}
