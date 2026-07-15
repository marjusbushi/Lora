<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FatureAlClient
{
    public function __construct(private readonly FatureAlConfiguration $configuration) {}

    /**
     * Read-only authentication check. It never creates or changes a fiscal record.
     *
     * @return array{company: string, nipt: string, branch: string}
     */
    public function testConnection(): array
    {
        if (! $this->configuration->configured()) {
            throw new RuntimeException('Integrimi fature.al nuk është aktiv ose token-i mungon.');
        }

        $response = Http::acceptJson()
            ->withToken($this->configuration->get('api_token'))
            ->timeout(12)
            ->connectTimeout(5)
            ->get(rtrim($this->configuration->get('base_url'), '/').'/account');

        if ($response->status() === 401) {
            throw new RuntimeException('Token-i i fature.al nuk u pranua.');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('fature.al kufizoi përkohësisht kërkesat. Provo përsëri pas pak.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('fature.al nuk u përgjigj siç pritej. Provo përsëri.');
        }

        $payload = $response->json();
        if (($payload['status'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
            throw new RuntimeException('Përgjigjja nga fature.al nuk ishte e vlefshme.');
        }

        return [
            'company' => (string) ($payload['data']['company'] ?? ''),
            'nipt' => (string) ($payload['data']['nipt'] ?? ''),
            'branch' => (string) ($payload['data']['branch']['name'] ?? ''),
        ];
    }
}
