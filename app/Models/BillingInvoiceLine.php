<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceLine extends Model
{
    protected $fillable = [
        'billing_invoice_id', 'type', 'module_code', 'description', 'quantity',
        'unit_amount_cents', 'amount_cents', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_amount_cents' => 'integer',
            'amount_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
