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

        // Tests dispatch without a context and pin to the migrated tenant.
        // Anywhere else a null stays null — UseTenantContext then refuses to
        // run the job instead of executing it against the wrong hotel.
        if ($this->tenantId === null && app()->environment('testing')) {
            $this->tenantId = Tenant::query()->active()->orderBy('id')->value('id');
        }
    }

    public function middleware(): array
    {
        return [new UseTenantContext($this->tenantId)];
    }
}
