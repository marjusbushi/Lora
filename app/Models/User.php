<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'current_tenant_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant_membership', function (Builder $builder) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $builder->whereExists(function ($query) use ($tenantId) {
                    $query->selectRaw('1')
                        ->from('tenant_user')
                        ->whereColumn('tenant_user.user_id', 'users.id')
                        ->where('tenant_user.tenant_id', $tenantId);
                });
            }
        });

        static::creating(function (User $user) {
            $user->current_tenant_id ??= static::resolvedTenantId();
        });

        static::created(function (User $user) {
            $tenantId = $user->current_tenant_id ?? static::resolvedTenantId();

            if ($tenantId !== null) {
                $user->tenants()->syncWithoutDetaching([
                    $tenantId => ['is_owner' => false],
                ]);
            }
        });
    }

    private static function resolvedTenantId(): ?int
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null && app()->runningInConsole()) {
            $tenantId = Tenant::query()->active()->orderBy('id')->value('id');
        }

        return $tenantId;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('is_owner')
            ->withTimestamps();
    }

    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /** Shared technical identity, attached separately to every active hotel. */
    public static function systemForCurrentTenant(): self
    {
        $user = static::withoutGlobalScopes()->withTrashed()->firstOrCreate(
            ['email' => 'system@villamucho.local'],
            ['name' => 'Website Booking', 'password' => Str::random(40)],
        );

        if ($user->trashed()) {
            $user->restore();
        }

        $tenantId = app(TenantContext::class)->id() ?? $user->current_tenant_id;
        if ($tenantId) {
            $user->tenants()->syncWithoutDetaching([$tenantId => ['is_owner' => false]]);
        }

        return $user;
    }
}
