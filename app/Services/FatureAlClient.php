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

    /**
     * Issue one cash-register invoice. The caller must always reuse internalId
     * when retrying so fature.al can return the existing document instead of a
     * duplicate.
     *
     * @return array<string, mixed>
     */
    public function createCashInvoice(array $payload): array
    {
        if (! $this->configuration->configured()) {
            throw new RuntimeException('Integrimi fature.al nuk është aktiv ose token-i mungon.');
        }

        if (blank($payload['internalId'] ?? null)) {
            throw new RuntimeException('internalId mungon nga fatura fiskale.');
        }

        $response = Http::acceptJson()
            ->withToken($this->configuration->get('api_token'))
            ->timeout(30)
            ->connectTimeout(5)
            ->post(rtrim($this->configuration->get('base_url'), '/').'/invoice/cash', $payload);

        if ($response->status() === 401) {
            throw new RuntimeException('Token-i i fature.al nuk u pranua.');
        }

        if ($response->status() === 409) {
            throw new RuntimeException('Fatura po përpunohet ende nga fature.al. Provo përsëri pas pak.');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('fature.al kufizoi përkohësisht kërkesat. Provo përsëri pas pak.');
        }

        if ($response->status() === 422) {
            $errors = $response->json('errors');
            $message = is_array($errors)
                ? collect($errors)->flatten()->filter()->take(3)->implode(' ')
                : null;

            throw new RuntimeException($message ?: 'fature.al refuzoi të dhënat e faturës.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('fature.al nuk u përgjigj siç pritej. Fatura nuk u konfirmua.');
        }

        $body = $response->json();
        $invoice = $body['data']['invoice'] ?? null;
        if (($body['status'] ?? false) !== true || ! is_array($invoice)) {
            throw new RuntimeException('Përgjigjja e fiskalizimit nga fature.al nuk ishte e vlefshme.');
        }

        return $invoice;
    }
}
