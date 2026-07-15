<?php

namespace App\Services;

use App\Models\TenantIntegration;

class IntegrationCatalog
{
    /**
     * Operational summaries only. Secret values never leave the server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forSettings(array $settings): array
    {
        $rows = TenantIntegration::query()->get()->keyBy('provider');

        $channex = $rows->get('channex');
        $pok = $rows->get('pok');
        $fature = $rows->get('fature_al');

        return [
            $this->tenantIntegration(
                'channex',
                'channels',
                $channex,
                filled($channex?->credentials['api_key'] ?? null)
                    && filled($channex?->configuration['property_id'] ?? null),
            ),
            $this->tenantIntegration(
                'pok',
                'payments',
                $pok,
                filled($pok?->credentials['key_id'] ?? null)
                    && filled($pok?->credentials['key_secret'] ?? null)
                    && filled($pok?->configuration['merchant_id'] ?? null),
            ),
            $this->tenantIntegration(
                'fature_al',
                'fiscalization',
                $fature,
                filled($fature?->credentials['api_token'] ?? null),
                true,
            ),
            $this->hotelIntegration(
                'gemini',
                'ai_data',
                (bool) ($settings['ai']['gemini_configured'] ?? false),
                'ai',
            ),
            $this->hotelIntegration(
                'exchange_rates',
                'ai_data',
                (bool) ($settings['currencies']['configured'] ?? false),
                'currencies',
                (bool) ($settings['currencies']['enabled'] ?? false),
            ),
            $this->hotelIntegration(
                'serp_api',
                'ai_data',
                (bool) ($settings['market_rates']['configured'] ?? false),
                'market-rates',
                (bool) ($settings['market_rates']['enabled'] ?? false),
            ),
        ];
    }

    private function tenantIntegration(
        string $id,
        string $category,
        ?TenantIntegration $integration,
        bool $hasRequiredCredentials,
        bool $testSupported = false,
    ): array {
        $enabled = (bool) ($integration?->enabled);
        $configured = $enabled && $hasRequiredCredentials;
        $configuration = $integration?->configuration ?? [];

        return [
            'id' => $id,
            'category' => $category,
            'enabled' => $enabled,
            'configured' => $configured,
            'status' => $configured ? 'configured' : ($enabled ? 'needs_attention' : 'inactive'),
            'managed_by' => 'lora',
            'settings_tab' => null,
            'environment' => $id === 'fature_al'
                ? (($configuration['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox')
                : null,
            'last_tested_at' => $configuration['last_tested_at'] ?? null,
            'last_test_status' => $configuration['last_test_status'] ?? null,
            'test_supported' => $testSupported && $configured,
        ];
    }

    private function hotelIntegration(
        string $id,
        string $category,
        bool $configured,
        string $settingsTab,
        bool $enabled = true,
    ): array {
        return [
            'id' => $id,
            'category' => $category,
            'enabled' => $enabled,
            'configured' => $configured,
            'status' => $configured ? ($enabled ? 'configured' : 'inactive') : 'needs_attention',
            'managed_by' => 'hotel',
            'settings_tab' => $settingsTab,
            'environment' => null,
            'last_tested_at' => null,
            'last_test_status' => null,
            'test_supported' => false,
        ];
    }
}
