<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\UseTenantContext;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use RuntimeException;

trait TenantAwareJob
{
    public ?int $tenantId = null;

    protected function captureTenant(): void
    {
        $this->tenantId = app(TenantContext::class)->id();

        // Legacy tests dispatch without a context and pin to the migrated
        // tenant. Runtime dispatches fail immediately when context is absent;
        // middleware also protects already-queued/legacy payloads.
        if ($this->tenantId === null && app()->environment('testing')) {
            $this->tenantId = Tenant::query()->active()->orderBy('id')->value('id');
        }

        if ($this->tenantId === null) {
            throw new RuntimeException('Cannot dispatch a hotel job without an active tenant context.');
        }
    }

    public function middleware(): array
    {
        return [new UseTenantContext($this->tenantId)];
    }
}
