<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;

final class TenantHandoff
{
    private const CACHE_PREFIX = 'lora:tenant-handoff:';

    /**
     * Create a short-lived bearer code. Only its SHA-256 hash is stored.
     */
    public function issue(User $user, Tenant $tenant, string $host): string
    {
        $host = Str::lower(trim($host));

        if (! $user->is_super_admin) {
            throw new LogicException('Only a super administrator can create a tenant handoff.');
        }

        if ($tenant->status !== 'active') {
            throw new LogicException('A handoff cannot be created for an inactive tenant.');
        }

        if (! $tenant->domains()->where('domain', $host)->exists()) {
            throw new LogicException('The handoff host does not belong to this tenant.');
        }

        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'user_id' => (int) $user->getKey(),
            'tenant_id' => (int) $tenant->getKey(),
            'host' => $host,
        ], now()->addSeconds($this->ttlSeconds()));

        return $token;
    }

    /**
     * Atomically consume a code on its intended tenant and host.
     *
     * A request from the wrong host does not burn the code, so a malformed or
     * malicious request cannot deny the legitimate handoff.
     */
    public function consume(string $token, Tenant $tenant, string $host): ?int
    {
        if (preg_match('/\A[A-Za-z0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        $cacheKey = $this->cacheKey($token);
        $host = Str::lower(trim($host));

        return Cache::lock($cacheKey.':lock', 10)->block(3, function () use ($cacheKey, $tenant, $host) {
            $payload = Cache::get($cacheKey);

            if (! is_array($payload)
                || (int) ($payload['tenant_id'] ?? 0) !== (int) $tenant->getKey()
                || ! hash_equals((string) ($payload['host'] ?? ''), $host)) {
                return null;
            }

            Cache::forget($cacheKey);

            $userId = filter_var($payload['user_id'] ?? null, FILTER_VALIDATE_INT);

            return $userId === false ? null : (int) $userId;
        });
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.hash('sha256', $token);
    }

    private function ttlSeconds(): int
    {
        return max(15, min(120, (int) config('lora.tenant_handoff_ttl_seconds', 60)));
    }
}
