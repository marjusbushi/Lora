<?php

namespace App\Console\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * Manual artisan runs must name the hotel they operate on. Without a tenant
 * context every tenant-scoped query silently sees ALL hotels' rows and any
 * write has no owner — with more than one hotel that mixes data across
 * tenants. Scheduled runs arrive with the context already set per tenant by
 * TenantCommandRunner; manual runs must pass --tenant=<ID>.
 */
trait ResolvesTenantContext
{
    protected function ensureTenantContext(): bool
    {
        $context = app(TenantContext::class);

        if ($context->id() !== null) {
            return true;
        }

        $option = $this->hasOption('tenant') ? $this->option('tenant') : null;

        if ($option === null || $option === '') {
            // Tests exercise commands against the single migrated tenant.
            if (app()->environment('testing')) {
                return true;
            }

            $this->error('Kjo komandë prek të dhënat e një hoteli: shto --tenant=<ID> (shiko: tenants në DB), ose lëre scheduler-in ta ekzekutojë për çdo hotel.');

            return false;
        }

        $tenant = Tenant::query()->active()->find((int) $option);

        if (! $tenant) {
            $this->error("Tenant {$option} nuk ekziston ose nuk është aktiv.");

            return false;
        }

        $context->set($tenant);

        return true;
    }
}
