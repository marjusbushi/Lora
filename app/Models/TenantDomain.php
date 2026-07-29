<?php

namespace App\Models;

use App\Support\TrustedHostPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    /** Lifecycle: the client points DNS, we provision the server, then it serves. */
    public const STATUS_PENDING_DNS = 'pending_dns';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['tenant_id', 'domain', 'is_primary', 'status', 'status_message', 'verified_at'];

    protected static function booted(): void
    {
        static::saved(static fn () => TrustedHostPatterns::forgetTenantDomains());
        static::deleted(static fn () => TrustedHostPatterns::forgetTenantDomains());
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
