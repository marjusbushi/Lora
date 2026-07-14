<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'status',
        'timezone',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Paid add-on catalog (price is metadata — billing is manual for now).
     * A tenant's granted add-ons live in metadata['addons'].
     */
    public const ADDONS = [
        'finance' => [
            'name' => 'Financa',
            'price_eur' => 29,
            'period' => 'muaj',
            'description' => 'Arka & Banka · Pagesat · Blerjet · Furnitorët · Monedhat',
        ],
    ];

    /** @return list<string> */
    public function addons(): array
    {
        $addons = $this->metadata['addons'] ?? [];

        return is_array($addons) ? array_values($addons) : [];
    }

    public function hasAddon(string $key): bool
    {
        return in_array($key, $this->addons(), true);
    }

    public function grantAddon(string $key): void
    {
        $meta = $this->metadata ?? [];
        $meta['addons'] = array_values(array_unique([...$this->addons(), $key]));
        if (array_key_exists($key, config('lora_modules.modules', [])) && is_array($meta['billing_access'] ?? null)) {
            $meta['billing_access']['modules'][$key] = true;
        }
        $this->update(['metadata' => $meta]);
    }

    public function revokeAddon(string $key): void
    {
        $meta = $this->metadata ?? [];
        $meta['addons'] = array_values(array_diff($this->addons(), [$key]));
        if (array_key_exists($key, config('lora_modules.modules', [])) && is_array($meta['billing_access'] ?? null)) {
            $meta['billing_access']['modules'][$key] = false;
        }
        $this->update(['metadata' => $meta]);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withoutGlobalScopes()
            ->withPivot(['is_owner', 'is_active'])
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(TenantIntegration::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    public function moduleEntitlements(): HasMany
    {
        return $this->hasMany(TenantModuleEntitlement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
