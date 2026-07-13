<?php

namespace App\Support;

use App\Tenancy\TenantContext;

/**
 * New uploads live under tenants/{id}/… so hotels never share directories
 * (per-tenant backup/quota/GDPR-wipe stays a plain folder operation).
 * Existing files keep their old flat paths — the stored path in the DB is
 * what gets served, so nothing needs migrating.
 */
class TenantStorage
{
    public static function path(string $dir): string
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId === null ? $dir : "tenants/{$tenantId}/{$dir}";
    }
}
