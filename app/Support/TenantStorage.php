<?php

namespace App\Support;

use App\Tenancy\TenantContext;
use InvalidArgumentException;

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
        $dir = trim($dir, '/');
        if ($dir === '' || in_array('..', explode('/', $dir), true)) {
            throw new InvalidArgumentException('Tenant storage directory must be a safe relative path.');
        }

        return 'tenants/'.app(TenantContext::class)->requireId().'/'.$dir;
    }
}
