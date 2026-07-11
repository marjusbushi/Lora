<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\UseTenantContext;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

trait TenantAwareJob
{
    public ?int $tenantId = null;

    protected function captureTenant(): void
    {
        $this->tenantId = app(TenantContext::class)->id();

        // Keeps explicit/manual Artisan dispatches compatible while there is
        // only the migrated default hotel. Scheduled work sets context itself.
        if ($this->tenantId === null && app()->runningInConsole()) {
            $this->tenantId = Tenant::query()->active()->orderBy('id')->value('id');
        }
    }

    public function middleware(): array
    {
        return [new UseTenantContext($this->tenantId)];
    }
}
