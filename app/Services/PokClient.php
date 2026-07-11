<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * POK (pokpay.io) server client for the EMBEDDED card-payment flow on the public booking site.
 *
 * Flow: login (keyId/keySecret → short-lived Bearer token, cached ~50m) → create an sdk-order
 * server-side to get the orderId the browser SDK (renderForm) mounts → after the guest pays,
 * VERIFY authoritatively via GET (never trust the webhook body). Amounts are MINOR units (cents).
 * Defaults to staging until POK_PRODUCTION=true. See the pok-embedded-contract memory for the
 * full recovered contract.
 */
class PokClient
{
    public function __construct(
        private readonly PokConfiguration $configuration,
        private readonly TenantContext $context,
    ) {}

    public function configured(): bool
    {
        return $this->configuration->configured();
    }

    private function base(): string
    {
        return rtrim((string) $this->configuration->get('base_url'), '/');
    }

    private function merchantId(): string
    {
        return (string) $this->configuration->get('merchant_id');
    }

    /** Bearer access token from POK, cached just under its ~1h TTL. */
    public function token(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(50), function () {
            $res = Http::asJson()->timeout(20)->post($this->base().'/auth/sdk/login', [
                'keyId' => $this->configuration->get('key_id'),
                'keySecret' => $this->configuration->get('key_secret'),
            ]);

            if (! $res->successful()) {
                throw new RuntimeException("POK login failed ({$res->status()}).");
            }

            $token = $res->json('data.accessToken');
            if (! $token) {
                throw new RuntimeException('POK login returned no accessToken.');
            }

            return (string) $token;
        });
    }

    /** Authenticated request; on a 401 (expired token) drop the cache and retry once. */
    private function authed(string $method, string $path, array $json = []): Response
    {
        $call = fn () => Http::withToken($this->token())->asJson()->timeout(30)
            ->{$method}($this->base().$path, $json);

        $res = $call();
        if ($res->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $res = $call();
        }

        return $res;
    }

    private function tokenCacheKey(): string
    {
        return 'pok.access_token.tenant.'.($this->context->id() ?? 'legacy');
    }

    /**
     * Create an sdk-order. $amount = price in MAJOR units — EUR 120 = €120, NOT cents
     * (verified last session + recorded in the POK architecture memory; the Postman examples
     * agree: 15000 ALL ≈ €150). Getting this wrong overcharges the guest 100×.
     * Returns the order the browser renderForm mounts.
     *
     * @param  array{webhook?:?string,redirect?:?string,fail?:?string,expires?:int}  $urls
     * @return array{id:string, finalAmount:float, currencyCode:string}
     */
    public function createOrder(int|float $amount, string $currency, array $urls = []): array
    {
        $res = $this->authed('post', '/merchants/'.$this->merchantId().'/sdk-orders', array_filter([
            'amount' => $amount,
            'currencyCode' => $currency,
            'autoCapture' => true,         // mandatory full prepayment → capture immediately
            'shippingCost' => 0,
            'webhookUrl' => $urls['webhook'] ?? null,
            'redirectUrl' => $urls['redirect'] ?? null,
            'failRedirectUrl' => $urls['fail'] ?? null,
            'expiresAfterMinutes' => $urls['expires'] ?? 30,
        ], fn ($v) => $v !== null));

        if (! $res->successful()) {
            throw new RuntimeException("POK create-order failed ({$res->status()}): ".$res->body());
        }

        $o = $res->json('data.sdkOrder') ?? [];
        if (empty($o['id'])) {
            throw new RuntimeException('POK create-order returned no order id.');
        }

        return [
            'id' => (string) $o['id'],
            'finalAmount' => (float) ($o['finalAmount'] ?? $amount),
            'currencyCode' => (string) ($o['currencyCode'] ?? $currency),
        ];
    }

    /**
     * Authoritative payment status for an order (server-side verification).
     * finalAmount is in MAJOR units (EUR), same as createOrder.
     *
     * @return array{isCompleted:bool, isCanceled:bool, isRefunded:bool, finalAmount:float, currencyCode:string}
     */
    public function getOrder(string $sdkOrderId): array
    {
        $res = $this->authed('get', '/merchants/'.$this->merchantId().'/sdk-orders/'.$sdkOrderId);

        if (! $res->successful()) {
            throw new RuntimeException("POK get-order failed ({$res->status()}).");
        }

        $o = $res->json('data.sdkOrder') ?? [];

        // Fail LOUD on response-shape drift: a missing finalAmount must NOT become 0 (that would
        // silently fail the amount check and discard a captured payment). Throw so callers keep
        // the hold + the anomaly surfaces, instead of quietly charging a guest with no booking.
        if (! array_key_exists('finalAmount', $o) || ! is_numeric($o['finalAmount'])) {
            throw new RuntimeException("POK get-order {$sdkOrderId}: response has no numeric finalAmount (shape drift?).");
        }

        return [
            'isCompleted' => (bool) ($o['isCompleted'] ?? false),
            'isCanceled' => (bool) ($o['isCanceled'] ?? false),
            'isRefunded' => (bool) ($o['isRefunded'] ?? false),
            'finalAmount' => (float) $o['finalAmount'],
            'currencyCode' => (string) ($o['currencyCode'] ?? ''),
        ];
    }
}
