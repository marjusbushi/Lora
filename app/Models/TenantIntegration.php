<?php

namespace App\Models;

class TenantIntegration extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'provider',
        'enabled',
        'credentials',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'configuration' => 'array',
        ];
    }
}
