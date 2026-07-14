<?php

namespace App\Support;

use App\Tenancy\TenantContext;

final class TenantKey
{
    public static function make(string $key): string
    {
        return 'tenant:'.app(TenantContext::class)->requireId().':'.$key;
    }
}
