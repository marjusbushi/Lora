<?php

namespace App\Services;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;

/** Resolves fature.al credentials only for the active hotel. */
class FatureAlConfiguration
{
    private ?array $resolved = null;

    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->context->id() === null) {
            return $this->resolved = $this->emptyConfig();
        }

        $integration = TenantIntegration::query()
            ->where('provider', 'fature_al')
            ->first();

        if (! $integration) {
            return $this->resolved = $this->emptyConfig();
        }

        $environment = ($integration->configuration['environment'] ?? 'sandbox') === 'production'
            ? 'production'
            : 'sandbox';

        return $this->resolved = [
            'enabled' => (bool) $integration->enabled,
            'api_token' => (string) ($integration->credentials['api_token'] ?? ''),
            'environment' => $environment,
            // The host is derived, never accepted from a browser request. This
            // prevents a stored URL from turning the integration into SSRF.
            'base_url' => $environment === 'production'
                ? 'https://fature.al/api/v1'
                : 'https://demo.fature.al/api/v1',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function configured(): bool
    {
        return $this->get('enabled', false) && $this->get('api_token', '') !== '';
    }

    private function emptyConfig(): array
    {
        return [
            'enabled' => false,
            'api_token' => '',
            'environment' => 'sandbox',
            'base_url' => 'https://demo.fature.al/api/v1',
        ];
    }
}
