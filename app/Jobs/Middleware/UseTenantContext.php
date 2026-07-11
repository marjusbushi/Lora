<?php

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use RuntimeException;

class UseTenantContext
{
    public function __construct(private readonly ?int $tenantId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $tenant = $this->tenantId ? Tenant::query()->active()->find($this->tenantId) : null;
        if (! $tenant) {
            throw new RuntimeException('Queued hotel operation has no active tenant context.');
        }

        return app(TenantContext::class)->run($tenant, fn () => $next($job));
    }
}
