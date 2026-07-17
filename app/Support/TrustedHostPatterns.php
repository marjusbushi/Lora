<?php

namespace App\Support;

use App\Models\TenantDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class TrustedHostPatterns
{
    private const TENANT_DOMAINS_CACHE_KEY = 'lora.trusted-hosts.tenant-domains.v1';

    /** @return list<string> */
    public static function all(): array
    {
        $hosts = array_merge(
            config('lora.marketing_hosts', []),
            config('lora.control_panel_hosts', []),
            config('lora.dedicated_control_panel_hosts', []),
            config('lora.additional_trusted_hosts', []),
        );

        if ($appHost = parse_url((string) config('app.url'), PHP_URL_HOST)) {
            $hosts[] = $appHost;
        }

        try {
            foreach (self::tenantDomains() as $domain) {
                $hosts[] = $domain;
                if (! str_starts_with(strtolower($domain), 'www.')) {
                    $hosts[] = 'www.'.$domain;
                }
            }
        } catch (Throwable $exception) {
            // Keep configured platform hosts available during a database outage,
            // while failing closed for tenant domains that cannot be verified.
            report($exception);
        }

        return collect($hosts)
            ->map(static fn ($host): string => strtolower(rtrim(trim((string) $host), '.')))
            ->filter(static fn (string $host): bool => $host !== '')
            ->unique()
            ->map(static fn (string $host): string => '^'.preg_quote($host, '/').'$')
            ->values()
            ->all();
    }

    public static function forgetTenantDomains(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            Cache::store('file')->forget(self::TENANT_DOMAINS_CACHE_KEY);
        } catch (Throwable $exception) {
            // Domain persistence must not fail because a disposable cache file
            // cannot be removed; the short TTL still bounds stale entries.
            report($exception);
        }
    }

    /** @return list<string> */
    private static function tenantDomains(): array
    {
        $load = static fn (): array => Schema::hasTable('tenant_domains')
            ? TenantDomain::query()->pluck('domain')->all()
            : [];

        if (app()->runningUnitTests()) {
            return $load();
        }

        $domains = Cache::store('file')->remember(
            self::TENANT_DOMAINS_CACHE_KEY,
            max(5, (int) config('lora.trusted_hosts_cache_seconds', 60)),
            $load,
        );

        return is_array($domains) ? $domains : [];
    }
}
