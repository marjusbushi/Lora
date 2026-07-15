<?php

namespace App\Models;

class PosFiscalDocument extends TenantModel
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FISCALIZED = 'fiscalized';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pos_order_id',
        'provider',
        'environment',
        'document_type',
        'internal_id',
        'payment_method',
        'currency',
        'exchange_rate',
        'total',
        'vat_rate',
        'invoice_payload',
        'request_hash',
        'status',
        'remote_id',
        'fiscal_number',
        'iic',
        'fic',
        'tcr_code',
        'business_code',
        'operator_code',
        'fiscalized_at',
        'verify_url',
        'pdf_url',
        'attempted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'total' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'invoice_payload' => 'array',
            'fiscalized_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }
}
