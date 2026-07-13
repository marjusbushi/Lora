<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Spatie\Permission\PermissionRegistrar;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->getKey());
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function idOrDefault(): ?int
    {
        // Only tests fall back to the single migrated tenant; in production a
        // missing context must stay null (fail-closed) instead of silently
        // resolving to the first hotel.
        return $this->id() ?? (app()->environment('testing')
            ? Tenant::query()->active()->orderBy('id')->value('id')
            : null);
    }

    public function clear(): void
    {
        $this->set(null);
    }

    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $this->set($previous);
        }
    }
}
