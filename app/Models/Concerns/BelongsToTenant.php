<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            // Fresh/upgrade migrations may query legacy operational tables
            // before the tenancy tables/columns exist. Outside that narrow
            // schema-bootstrap window, missing context always fails closed.
            if (app(TenantContext::class)->schemaBootstrapActive() && (
                ! Schema::hasTable('tenants')
                || ! Schema::hasColumn($builder->getModel()->getTable(), 'tenant_id')
            )) {
                return;
            }

            $tenantId = static::resolvedTenantId();

            if ($tenantId !== null) {
                $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);

                return;
            }

            // A missing context must never mean "all hotels". Central/control-
            // plane queries must opt in explicitly with withoutGlobalScopes().
            $builder->where($builder->qualifyColumn('tenant_id'), 0);
        });

        static::creating(function ($model) {
            $tenantId = static::requireTenantId($model);

            if ($model->tenant_id !== null && (int) $model->tenant_id !== $tenantId) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s for tenant %s while tenant %s is active.',
                    $model::class,
                    $model->tenant_id,
                    $tenantId,
                ));
            }

            $model->tenant_id = $tenantId;
        });

        static::updating(function ($model) {
            $tenantId = static::requireTenantId($model);

            if ($model->isDirty('tenant_id') || (int) $model->tenant_id !== $tenantId) {
                throw new RuntimeException(sprintf(
                    'Cannot move or update %s across tenants.',
                    $model::class,
                ));
            }
        });

        static::deleting(function ($model) {
            static::assertOwnedByActiveTenant($model);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    private static function resolvedTenantId(): ?int
    {
        $tenantId = app(TenantContext::class)->id();

        // Legacy tests build records directly without HTTP middleware. Keep
        // that convenience test-only; production always stays fail-closed.
        if ($tenantId === null && app()->environment('testing') && Schema::hasTable('tenants')) {
            $tenantId = Tenant::query()->active()->orderBy('id')->value('id');
        }

        return $tenantId !== null ? (int) $tenantId : null;
    }

    private static function requireTenantId($model): int
    {
        $tenantId = static::resolvedTenantId();

        if ($tenantId === null) {
            throw new RuntimeException(sprintf(
                'Cannot mutate %s without a tenant context — run inside TenantContext::run() or pass --tenant=<ID>.',
                $model::class,
            ));
        }

        return $tenantId;
    }

    private static function assertOwnedByActiveTenant($model): void
    {
        $tenantId = static::requireTenantId($model);

        if ((int) $model->tenant_id !== $tenantId) {
            throw new RuntimeException(sprintf(
                'Cannot mutate %s across tenants.',
                $model::class,
            ));
        }
    }
}
