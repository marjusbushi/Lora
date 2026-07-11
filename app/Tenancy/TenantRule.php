<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('tenant_id', self::tenantId());
    }

    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)
            ->where('tenant_id', self::tenantId());
    }

    /** Users are global identities; membership is tenant-specific in the pivot. */
    public static function userExists(): Exists
    {
        return Rule::exists('tenant_user', 'user_id')
            ->where('tenant_id', self::tenantId());
    }

    private static function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->id();
        abort_unless($tenantId, 404, 'Hotel not found.');

        return $tenantId;
    }
}
