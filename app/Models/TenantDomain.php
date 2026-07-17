<?php

namespace App\Models;

use App\Support\TrustedHostPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    protected $fillable = ['tenant_id', 'domain', 'is_primary'];

    protected static function booted(): void
    {
        static::saved(static fn () => TrustedHostPatterns::forgetTenantDomains());
        static::deleted(static fn () => TrustedHostPatterns::forgetTenantDomains());
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
