<?php

namespace App\Console\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * Manual artisan runs must name the hotel they operate on. Without a tenant
 * context, tenant-scoped reads and writes fail closed. Scheduled runs arrive
 * with the context already set per tenant by TenantCommandRunner; manual runs
 * must pass --tenant=<ID>.
 */
trait ResolvesTenantContext
{
    protected function ensureTenantContext(): bool
    {
        $context = app(TenantContext::class);
        $option = $this->hasOption('tenant') ? $this->option('tenant') : null;

        if ($context->id() !== null) {
            if ($option !== null && $option !== '') {
                $tenantId = $this->validatedTenantId($option);

                if ($tenantId === null || $tenantId !== $context->id()) {
                    $this->error('Tenant-i i kërkuar nuk përputhet me kontekstin aktiv.');

                    return false;
                }
            }

            return true;
        }

        if ($option === null || $option === '') {
            // Tests exercise commands against the single migrated tenant.
            if (app()->environment('testing')) {
                return true;
            }

            $this->error('Kjo komandë prek të dhënat e një hoteli: shto --tenant=<ID> (shiko: tenants në DB), ose lëre scheduler-in ta ekzekutojë për çdo hotel.');

            return false;
        }

        $tenantId = $this->validatedTenantId($option);

        if ($tenantId === null) {
            $this->error('Opsioni --tenant duhet të jetë një ID numerike pozitive.');

            return false;
        }

        $tenant = Tenant::query()->active()->find($tenantId);

        if (! $tenant) {
            $this->error("Tenant {$option} nuk ekziston ose nuk është aktiv.");

            return false;
        }

        $context->set($tenant);

        return true;
    }

    private function validatedTenantId(mixed $value): ?int
    {
        $tenantId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $tenantId === false ? null : $tenantId;
    }
}
