<?php

namespace App\Services;

use App\Models\ChannelSyncLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Channex.io v1 REST API (channel manager: Booking.com,
 * Airbnb, Expedia, ...). Auth is a single account API key sent as the
 * `user-api-key` header. One Channex account holds many properties, so the
 * property id is passed per call (defaulting to the configured one) — this keeps
 * the client usable for a multi-hotel product, not just one property.
 *
 * Verified gotchas baked in (see the channex-pilot memory / task #153):
 *  - rates are in MINOR units (cents): €80.00 is sent as 8000.
 *  - a per_room rate plan carries exactly ONE occupancy option.
 *  - ARI pushes are async: a 2xx returns a background task id, not final state.
 *
 * Mirrors App\Services\Beds24Client in shape (config-driven, configured(),
 * request helpers); reads are idempotent and retried, structure-creating writes
 * are single-shot (a retry could duplicate a room type). Every state-changing
 * call leaves a ChannelSyncLog audit row so a failed sync is never invisible.
 */
class ChannexClient
{
    /** Channex paginates list endpoints (default 10/page); ask for the max. */
    private const PAGE_LIMIT = 100;

    protected string $apiKey;
    protected string $baseUrl;
    protected string $propertyId;

    public function __construct()
    {
        $this->apiKey = (string) config('services.channex.api_key');
        $this->baseUrl = rtrim((string) config('services.channex.base_url'), '/');
        $this->propertyId = (string) config('services.channex.property_id');
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    /** €80.00 -> 8000. Channex stores rates in minor currency units. */
    public static function toCents(float $price): int
    {
        return (int) round($price * 100);
    }

    /** 8000 -> 80.00. Inverse of toCents, for reading rates back. */
    public static function fromCents(int|string $cents): float
    {
        return ((int) $cents) / 100;
    }

    // -- reads (idempotent: retried) --------------------------------------

    /** Every property on the account. */
    public function getProperties(): array
    {
        return $this->getList('/properties');
    }

    /** Room types for a property (default: the configured one). */
    public function getRoomTypes(?string $propertyId = null): array
    {
        return $this->getList('/room_types', ['filter' => ['property_id' => $propertyId ?: $this->propertyId]]);
    }

    /** Rate plans for a property (default: the configured one). */
    public function getRatePlans(?string $propertyId = null): array
    {
        return $this->getList('/rate_plans', ['filter' => ['property_id' => $propertyId ?: $this->propertyId]]);
    }

    /**
     * GET a JSON:API list endpoint. Throws on a non-2xx so a bad key / lost
     * access surfaces loudly instead of masquerading as an empty list, and
     * requests a full page (Channex defaults to only 10) so link/sync never
     * silently truncate — a still-larger result is reported, not dropped.
     */
    protected function getList(string $path, array $query = []): array
    {
        $query['pagination'] = ['page' => 1, 'limit' => self::PAGE_LIMIT];
        $resp = $this->http(idempotent: true)->get($this->baseUrl.$path, $query);

        if (! $resp->successful()) {
            throw new RuntimeException("Channex GET {$path} failed: HTTP {$resp->status()}");
        }

        $total = (int) ($resp->json('meta.total') ?? 0);
        if ($total > self::PAGE_LIMIT) {
            report(new RuntimeException("Channex {$path}: {$total} items exceed the page limit of ".self::PAGE_LIMIT.'; some were not fetched.'));
        }

        return $resp->json('data') ?? [];
    }

    // -- writes: create structure (single-shot) ---------------------------

    /**
     * Create a room type. $occupancy = max guests (mapped to occ_adults +
     * default_occupancy). Returns the Channex room_type id, or null on failure.
     */
    public function createRoomType(string $title, int $countOfRooms, int $occupancy, ?string $propertyId = null): ?string
    {
        $payload = ['room_type' => [
            'property_id' => $propertyId ?: $this->propertyId,
            'title' => $title,
            'count_of_rooms' => $countOfRooms,
            'occ_adults' => $occupancy,
            'occ_children' => 0,
            'occ_infants' => 0,
            'default_occupancy' => $occupancy,
        ]];
        $resp = $this->http()->post("{$this->baseUrl}/room_types", $payload);
        $this->log('push', 'create_room_type', $payload, $resp);

        return $resp->successful() ? $resp->json('data.id') : null;
    }

    /**
     * Create a per_room manual rate plan for a room type. Channex requires
     * exactly ONE occupancy option for per_room (>1 => 422). Returns the id.
     */
    public function createRatePlan(string $roomTypeId, int $occupancy, string $title = 'Standard Rate', string $currency = 'EUR', ?string $propertyId = null): ?string
    {
        $payload = ['rate_plan' => [
            'property_id' => $propertyId ?: $this->propertyId,
            'room_type_id' => $roomTypeId,
            'title' => $title,
            'sell_mode' => 'per_room',
            'rate_mode' => 'manual',
            'currency' => $currency,
            'options' => [['occupancy' => $occupancy, 'is_primary' => true]],
        ]];
        $resp = $this->http()->post("{$this->baseUrl}/rate_plans", $payload);
        $this->log('push', 'create_rate_plan', $payload, $resp);

        return $resp->successful() ? $resp->json('data.id') : null;
    }

    // -- writes: ARI push (idempotent: retried) ---------------------------

    /** Push availability (rooms free) for a room type over an inclusive range. */
    public function pushAvailability(string $roomTypeId, string $dateFrom, string $dateTo, int $available, ?string $propertyId = null): bool
    {
        $payload = ['values' => [[
            'property_id' => $propertyId ?: $this->propertyId,
            'room_type_id' => $roomTypeId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'availability' => $available,
        ]]];
        $resp = $this->http(idempotent: true)->post("{$this->baseUrl}/availability", $payload);
        $this->log('push', 'availability', $payload, $resp);

        return $resp->successful();
    }

    /**
     * Push a nightly rate for a rate plan over an inclusive range. $price is in
     * MAJOR units (euros); Channex wants cents, so it is converted here (gotcha #1).
     */
    public function pushRate(string $ratePlanId, string $dateFrom, string $dateTo, float $price, ?string $propertyId = null): bool
    {
        $payload = ['values' => [[
            'property_id' => $propertyId ?: $this->propertyId,
            'rate_plan_id' => $ratePlanId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'rate' => self::toCents($price),
        ]]];
        $resp = $this->http(idempotent: true)->post("{$this->baseUrl}/restrictions", $payload);
        $this->log('push', 'rate', $payload, $resp);

        return $resp->successful();
    }

    // -- internals --------------------------------------------------------

    protected function http(bool $idempotent = false): PendingRequest
    {
        $req = Http::withHeaders(['user-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(30);

        if (! $idempotent) {
            // Creates are single-shot: a retry could duplicate a room type / rate plan.
            return $req;
        }

        // Idempotent calls (reads, ARI set-value) retry only TRANSIENT failures
        // (connection error / 5xx). A 4xx is a permanent client error — retrying
        // wastes time. throw:false -> the caller inspects the response (getList
        // throws on !ok; ARI pushes log + return bool).
        return $req->retry(3, 250, function (\Throwable $e) {
            return ! ($e instanceof RequestException) || (bool) $e->response?->serverError();
        }, throw: false);
    }

    /** Best-effort audit row; a logging failure must never break the sync. */
    protected function log(string $direction, string $action, array $request, Response $response): void
    {
        $body = $response->json();

        ChannelSyncLog::record([
            'channel' => 'channex',
            'direction' => $direction,
            'action' => $action,
            'status' => $response->successful() ? 'ok' : 'error',
            'request' => $request,
            'response' => is_array($body) ? $body : ['raw' => $response->body()],
            'error' => $response->successful() ? null : "HTTP {$response->status()}",
        ]);
    }
}
