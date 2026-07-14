<?php

namespace App\Services;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;

/** Resolves POK merchant credentials for the active hotel. */
class PokConfiguration
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

        // Legacy env credentials exist ONLY for the test suite. In production a
        // missing tenant context must resolve to NO credentials — never to the
        // first hotel's (Villa Mucho's) live account.
        if (app()->environment('testing') && config('services.pok.testing_legacy_fallback', true)) {
            return $this->resolved = $this->legacyConfig();
        }

        if ($this->context->id() === null) {
            return $this->resolved = $this->emptyConfig();
        }

        if (! $this->billing->enabled(TenantBillingService::BOOKING_ENGINE, $this->context->tenant())) {
            return $this->resolved = $this->emptyConfig();
        }

        $integration = TenantIntegration::query()
            ->where('provider', 'pok')
            ->where('enabled', true)
            ->first();

        if (! $integration) {
            return $this->resolved = $this->emptyConfig();
        }

        $credentials = $integration->credentials ?? [];
        $configuration = $integration->configuration ?? [];
        $production = (bool) ($configuration['production'] ?? false);

        return $this->resolved = [
            'key_id' => (string) ($credentials['key_id'] ?? ''),
            'key_secret' => (string) ($credentials['key_secret'] ?? ''),
            'merchant_id' => (string) ($configuration['merchant_id'] ?? ''),
            'production' => $production,
            'base_url' => (string) ($configuration['base_url'] ?? ($production
                ? 'https://api.pokpay.io'
                : 'https://api-staging.pokpay.io')),
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function configured(): bool
    {
        return $this->get('key_id', '') !== ''
            && $this->get('key_secret', '') !== ''
            && $this->get('merchant_id', '') !== '';
    }

    public function payUrl(): string
    {
        return $this->get('production', false)
            ? 'https://pay.pokpay.io'
            : 'https://pay-staging.pokpay.io';
    }

    private function legacyConfig(): array
    {
        return [
            'key_id' => (string) config('services.pok.key_id'),
            'key_secret' => (string) config('services.pok.key_secret'),
            'merchant_id' => (string) config('services.pok.merchant_id'),
            'production' => (bool) config('services.pok.production'),
            'base_url' => (string) config('services.pok.base_url'),
        ];
    }

    private function emptyConfig(): array
    {
        return [
            'key_id' => '',
            'key_secret' => '',
            'merchant_id' => '',
            'production' => false,
            'base_url' => 'https://api-staging.pokpay.io',
        ];
    }
}
