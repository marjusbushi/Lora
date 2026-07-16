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
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'current_tenant_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant_membership', function (Builder $builder) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $builder->whereExists(function ($query) use ($tenantId) {
                    $query->selectRaw('1')
                        ->from('tenant_user')
                        ->whereColumn('tenant_user.user_id', 'users.id')
                        ->where('tenant_user.tenant_id', $tenantId)
                        ->where('tenant_user.is_active', true);
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
                    $tenantId => ['is_owner' => false, 'is_active' => true],
                ]);
            }
        });
    }

    private static function resolvedTenantId(): ?int
    {
        $tenantId = app(TenantContext::class)->id();

        // Only tests fall back to the single migrated tenant — a console run
        // without context must NOT silently attach users to the first hotel.
        if ($tenantId === null && app()->environment('testing')) {
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
            ->withPivot(['is_owner', 'is_active'])
            ->withTimestamps();
    }

    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()->wherePivot('is_active', true);
    }

    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /** The legacy shared system identity of the first migrated hotel. */
    public const LEGACY_SYSTEM_EMAIL = 'system@villamucho.local';

    /** True for any hotel's technical booking identity (legacy or per-tenant). */
    public static function isSystemEmail(?string $email): bool
    {
        return $email === self::LEGACY_SYSTEM_EMAIL
            || ($email !== null && preg_match('/^system\+t\d+@lora\.local$/', $email) === 1);
    }

    /**
     * Technical identity that public/OTA bookings are attributed to — one PER
     * HOTEL, so tenants never share a creator account. The first migrated
     * hotel keeps its historic legacy identity (old reservations' created_by
     * stays meaningful); every other hotel gets its own system+t{id} user.
     */
    public static function systemForCurrentTenant(): self
    {
        $tenantId = app(TenantContext::class)->id();

        $user = null;
        if ($tenantId !== null) {
            $legacy = static::withoutGlobalScopes()->withTrashed()
                ->where('email', self::LEGACY_SYSTEM_EMAIL)
                ->first();

            if ($legacy && $legacy->tenants()->whereKey($tenantId)->exists()) {
                $user = $legacy;
            }
        }

        $user ??= static::withoutGlobalScopes()->withTrashed()->firstOrCreate(
            ['email' => $tenantId === null ? self::LEGACY_SYSTEM_EMAIL : "system+t{$tenantId}@lora.local"],
            ['name' => 'Website Booking', 'password' => Str::random(40)],
        );

        if ($user->trashed()) {
            $user->restore();
        }

        $tenantId = $tenantId ?? $user->current_tenant_id;
        if ($tenantId) {
            $user->tenants()->syncWithoutDetaching([
                $tenantId => ['is_owner' => false, 'is_active' => true],
            ]);
        }

        return $user;
    }
}
