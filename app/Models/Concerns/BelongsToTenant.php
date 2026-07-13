<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function ($model) {
            if ($model->tenant_id !== null) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            // Tests build models without an explicit context and pin to the
            // single migrated tenant. Everywhere else a missing context must
            // FAIL, never silently write into the first hotel's data.
            if ($tenantId === null && app()->environment('testing')) {
                $tenantId = Tenant::query()->active()->orderBy('id')->value('id');
            }

            if ($tenantId === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s without a tenant context — run inside TenantContext::run() or pass --tenant=<ID> to the command.',
                    $model::class,
                ));
            }

            $model->tenant_id = $tenantId;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
