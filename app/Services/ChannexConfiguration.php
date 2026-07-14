<?php

namespace App\Services;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;

/** Resolves Channex credentials for the active hotel; never crosses tenants. */
class ChannexConfiguration
{
    private ?array $resolved = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantBillingService $billing,
    ) {}

    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // Existing unit/feature tests intentionally configure isolated fake API
        // credentials in config and never contain real tenant secrets.
        // Legacy env credentials exist ONLY for the test suite. In production a
        // missing tenant context must resolve to NO credentials — never to the
        // first hotel's (Villa Mucho's) live account.
        if (app()->environment('testing') && config('services.channex.testing_legacy_fallback', true)) {
            return $this->resolved = $this->legacyConfig();
        }

        if ($this->context->id() === null) {
            return $this->resolved = $this->emptyConfig();
        }

        if (! $this->billing->enabled(TenantBillingService::CHANNEL_MANAGER, $this->context->tenant())) {
            return $this->resolved = $this->emptyConfig();
        }

        $integration = TenantIntegration::query()
            ->where('provider', 'channex')
            ->where('enabled', true)
            ->first();

        if (! $integration) {
            return $this->resolved = $this->emptyConfig();
        }

        $credentials = $integration->credentials ?? [];
        $configuration = $integration->configuration ?? [];

        return $this->resolved = [
            'api_key' => (string) ($credentials['api_key'] ?? ''),
            'webhook_secret' => (string) ($credentials['webhook_secret'] ?? ''),
            'base_url' => (string) ($configuration['base_url'] ?? 'https://app.channex.io/api/v1'),
            'property_id' => (string) ($configuration['property_id'] ?? ''),
            'state_length_days' => (int) ($configuration['state_length_days'] ?? 500),
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function configured(): bool
    {
        return $this->get('api_key', '') !== '' && $this->get('property_id', '') !== '';
    }

    private function legacyConfig(): array
    {
        return [
            'api_key' => (string) config('services.channex.api_key'),
            'webhook_secret' => (string) config('services.channex.webhook_secret'),
            'base_url' => (string) config('services.channex.base_url', 'https://app.channex.io/api/v1'),
            'property_id' => (string) config('services.channex.property_id'),
            'state_length_days' => (int) config('services.channex.state_length_days', 500),
        ];
    }

    private function emptyConfig(): array
    {
        return [
            'api_key' => '',
            'webhook_secret' => '',
            'base_url' => 'https://app.channex.io/api/v1',
            'property_id' => '',
            'state_length_days' => 500,
        ];
    }
}
