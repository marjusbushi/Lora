<?php

namespace App\Models;

class FiscalDocument extends TenantModel
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FISCALIZED = 'fiscalized';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'reservation_id',
        'provider',
        'environment',
        'document_type',
        'internal_id',
        'payment_method',
        'currency',
        'total',
        'vat_rate',
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
            'total' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'fiscalized_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
