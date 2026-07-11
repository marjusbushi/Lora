<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
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
        return $this->id() ?? (app()->runningInConsole()
            ? Tenant::query()->active()->orderBy('id')->value('id')
            : null);
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }
}
