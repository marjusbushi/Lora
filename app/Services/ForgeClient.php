<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin Laravel Forge API client for provisioning hotel domains on the
 * platform's own server: one Forge site per domain (mirroring the layout
 * already live in production), nginx pointed at the shared app root, and a
 * Let's Encrypt certificate covering apex + www. Credentials come from
 * config/services.php (env) and never appear in code or logs.
 */
class ForgeClient
{
    private const BASE_URL = 'https://forge.laravel.com/api/v1';

    public function configured(): bool
    {
        return $this->token() !== '' && $this->serverId() !== '';
    }

    /**
     * The public IP hotel DNS must point at (differs per environment).
     *
     * FORGE_SERVER_IP wins when set (proxies/CDN can make it differ), but the
     * default is self-detection: client domains must point at the same front
     * door that serves this app, so resolving our own APP_URL host is the
     * right answer without any configuration. Loopback/private results (local
     * dev) are suppressed so nonsense instructions are never shown.
     */
    public static function serverIp(): string
    {
        $configured = trim((string) config('services.forge.server_ip', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : Cache::remember('forge.server_ip.resolved.'.$host, now()->addHour(), function () use ($host) {
                $resolved = gethostbyname($host);

                return $resolved === $host ? '' : $resolved;
            });

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : '';
    }

    /** The shared application document root every domain site serves. */
    public static function appRoot(): string
    {
        return trim((string) config('services.forge.app_root', ''));
    }

    /** @return array<string, mixed>|null the Forge site whose name matches the domain */
    public function siteByDomain(string $domain): ?array
    {
        $sites = $this->request()->get($this->serverPath('/sites'))->json('sites') ?? [];

        foreach ($sites as $site) {
            if (strcasecmp((string) ($site['name'] ?? ''), $domain) === 0) {
                return $site;
            }
        }

        return null;
    }

    /** @return array<string, mixed> the created (or already existing) site */
    public function createSite(string $domain): array
    {
        if ($existing = $this->siteByDomain($domain)) {
            return $existing;
        }

        $response = $this->request()->post($this->serverPath('/sites'), [
            'domain' => $domain,
            'project_type' => 'php',
            'aliases' => ['www.'.$domain],
            'directory' => '/public',
            'isolated' => false,
            'username' => config('services.forge.site_user', 'lorapms'),
        ])->throw();

        return $response->json('site') ?? [];
    }

    /** Point the fresh site's nginx root at the shared application. */
    public function pointSiteAtAppRoot(int $siteId): void
    {
        $appRoot = self::appRoot();
        if ($appRoot === '') {
            return;
        }

        $nginx = (string) $this->request()->get($this->serverPath("/sites/{$siteId}/nginx"))->throw()->body();
        $rewritten = preg_replace('/root\s+[^;]+;/', "root {$appRoot};", $nginx, 1);

        if ($rewritten !== null && $rewritten !== $nginx) {
            $this->request()->put($this->serverPath("/sites/{$siteId}/nginx"), [
                'content' => $rewritten,
            ])->throw();
        }
    }

    /** @param list<string> $domains */
    public function requestLetsEncrypt(int $siteId, array $domains): void
    {
        $this->request()->post($this->serverPath("/sites/{$siteId}/certificates/letsencrypt"), [
            'domains' => $domains,
        ])->throw();
    }

    public function hasActiveCertificate(int $siteId): bool
    {
        $certificates = $this->request()
            ->get($this->serverPath("/sites/{$siteId}/certificates"))
            ->json('certificates') ?? [];

        foreach ($certificates as $certificate) {
            if (($certificate['status'] ?? '') === 'installed' || ($certificate['active'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->baseUrl(self::BASE_URL);
    }

    private function serverPath(string $suffix): string
    {
        return '/servers/'.$this->serverId().$suffix;
    }

    private function token(): string
    {
        return trim((string) config('services.forge.token', ''));
    }

    private function serverId(): string
    {
        return trim((string) config('services.forge.server_id', ''));
    }
}
