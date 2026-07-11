<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

            // Seeders and legacy console commands run outside HTTP middleware.
            // During the transition they stay pinned to the single/default tenant.
            if ($tenantId === null && app()->runningInConsole()) {
                $tenantId = Tenant::query()->active()->orderBy('id')->value('id');
            }

            if ($tenantId !== null) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
