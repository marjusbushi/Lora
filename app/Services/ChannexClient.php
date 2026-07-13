<?php

namespace App\Services;

use App\Models\ChannelSyncLog;
use Carbon\CarbonInterface;
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

    protected string $webhookSecret;

    public function __construct(?ChannexConfiguration $configuration = null)
    {
        $configuration ??= app(ChannexConfiguration::class);
        $this->apiKey = (string) $configuration->get('api_key', '');
        $this->baseUrl = rtrim((string) $configuration->get('base_url', 'https://app.channex.io/api/v1'), '/');
        $this->propertyId = (string) $configuration->get('property_id', '');
        $this->webhookSecret = (string) $configuration->get('webhook_secret', '');
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
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

    /** Read room-type availability for an inclusive range (used to verify a cutoff closure). */
    public function getAvailabilityRange(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $propertyId = null,
    ): array {
        $resp = $this->http(timeout: 20)->get("{$this->baseUrl}/availability", [
            'filter' => [
                'property_id' => $propertyId ?: $this->propertyId,
                'date' => [
                    'gte' => $from->toDateString(),
                    'lte' => $to->toDateString(),
                ],
            ],
        ]);

        if (! $resp->successful()) {
            throw new RuntimeException("Channex availability verification failed: HTTP {$resp->status()}");
        }

        return $resp->json('data') ?? [];
    }

    /** Read per-rate-plan nightly rates for an inclusive range. */
    public function getRateRange(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $propertyId = null,
    ): array {
        $resp = $this->http(timeout: 20)->get("{$this->baseUrl}/restrictions", [
            'filter' => [
                'property_id' => $propertyId ?: $this->propertyId,
                'date' => [
                    'gte' => $from->toDateString(),
                    'lte' => $to->toDateString(),
                ],
                'restrictions' => 'rate',
            ],
        ]);

        if (! $resp->successful()) {
            throw new RuntimeException("Channex rate verification failed: HTTP {$resp->status()}");
        }

        return $resp->json('data') ?? [];
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

    // -- writes: ARI push (single HTTP attempt; queue job retries) --------

    /** Push availability (rooms free) for a room type over one inclusive range. */
    public function pushAvailability(
        string $roomTypeId,
        string $dateFrom,
        string $dateTo,
        int $available,
        ?string $propertyId = null,
        ?int $pmsRoomTypeId = null,
    ): bool {
        return $this->pushAvailabilityRanges($roomTypeId, [
            ['date_from' => $dateFrom, 'date_to' => $dateTo, 'availability' => $available],
        ], $propertyId, $pmsRoomTypeId);
    }

    /**
     * Push availability for a room type across MANY inclusive date ranges in one
     * call. $ranges = [['date_from'=>'Y-m-d','date_to'=>'Y-m-d','availability'=>int], ...].
     * An empty $ranges is a no-op success (nothing to send).
     */
    public function pushAvailabilityRanges(
        string $roomTypeId,
        array $ranges,
        ?string $propertyId = null,
        ?int $pmsRoomTypeId = null,
    ): bool {
        if ($ranges === []) {
            return true;
        }
        $pid = $propertyId ?: $this->propertyId;
        $values = array_map(fn ($r) => [
            'property_id' => $pid,
            'room_type_id' => $roomTypeId,
            'date_from' => $r['date_from'],
            'date_to' => $r['date_to'],
            'availability' => (int) $r['availability'],
        ], $ranges);

        // Queue jobs own the retry/backoff. One HTTP attempt keeps each job
        // comfortably below the database queue's retry_after window.
        $resp = $this->http(timeout: 20)->post("{$this->baseUrl}/availability", ['values' => $values]);
        $accepted = $this->ariAccepted($resp);
        $this->log('push', 'availability', ['values' => $values], $resp, $accepted, $pmsRoomTypeId);

        return $accepted;
    }

    /**
     * Push a nightly rate for a rate plan over an inclusive range. $price is in
     * MAJOR units (euros); Channex wants cents, so it is converted here (gotcha #1).
     */
    public function pushRate(
        string $ratePlanId,
        string $dateFrom,
        string $dateTo,
        float $price,
        ?string $propertyId = null,
        ?int $pmsRoomTypeId = null,
    ): bool {
        return $this->pushRateRanges($ratePlanId, [
            ['date_from' => $dateFrom, 'date_to' => $dateTo, 'rate' => $price],
        ], $propertyId, $pmsRoomTypeId);
    }

    /**
     * Push rates for a rate plan across MANY inclusive date ranges in one call.
     * Each range's 'rate' is in EUROS (major units) and converted to cents here.
     * An empty $ranges is a no-op success.
     */
    public function pushRateRanges(
        string $ratePlanId,
        array $ranges,
        ?string $propertyId = null,
        ?int $pmsRoomTypeId = null,
    ): bool {
        if ($ranges === []) {
            return true;
        }
        $pid = $propertyId ?: $this->propertyId;
        $values = array_map(fn ($r) => [
            'property_id' => $pid,
            'rate_plan_id' => $ratePlanId,
            'date_from' => $r['date_from'],
            'date_to' => $r['date_to'],
            'rate' => self::toCents((float) $r['rate']),
        ], $ranges);

        $resp = $this->http(timeout: 20)->post("{$this->baseUrl}/restrictions", ['values' => $values]);
        $accepted = $this->ariAccepted($resp);
        $this->log('push', 'rate', ['values' => $values], $resp, $accepted, $pmsRoomTypeId);

        return $accepted;
    }

    // -- bookings (inbound: OTA -> PMS) -----------------------------------

    /**
     * Fetch one booking revision by id. Returns the JSON:API resource
     * ({id, type, attributes:{...}}) or null. Throws on a non-2xx.
     */
    public function getBookingRevision(string $id): ?array
    {
        $resp = $this->http(idempotent: true)->get("{$this->baseUrl}/booking_revisions/{$id}");
        if (! $resp->successful()) {
            throw new RuntimeException("Channex GET booking_revisions/{$id} failed: HTTP {$resp->status()}");
        }

        return $resp->json('data');
    }

    /** Unacknowledged booking revisions (the canonical catch-up feed). */
    public function getBookingFeed(): array
    {
        return $this->getList('/booking_revisions/feed');
    }

    /**
     * Acknowledge a revision so Channex stops re-delivering it. Only call after
     * the revision has been imported successfully.
     */
    public function ackBookingRevision(string $id): bool
    {
        $resp = $this->http(idempotent: true)->post("{$this->baseUrl}/booking_revisions/{$id}/ack");
        $this->log('pull', 'ack_booking', ['revision_id' => $id], $resp);

        return $resp->successful();
    }

    /** Fetch one booking (attributes carry ota_reservation_code = the OTA's own ref). */
    public function getBooking(string $id): ?array
    {
        $resp = $this->http(idempotent: true)->get("{$this->baseUrl}/bookings/{$id}");

        return $resp->successful() ? $resp->json('data') : null;
    }

    /** Close a guest-message thread on Channex (mirrors our 'closed' status). */
    public function closeMessageThread(string $id): void
    {
        $resp = $this->http()->post("{$this->baseUrl}/message_threads/{$id}/close");
        $this->log('push', 'close_thread', ['thread_id' => $id], $resp);

        if (! $resp->successful()) {
            throw new RuntimeException("Channex close thread {$id} failed: HTTP {$resp->status()}");
        }
    }

    /** Reopen a closed guest-message thread on Channex. */
    public function openMessageThread(string $id): void
    {
        $resp = $this->http()->post("{$this->baseUrl}/message_threads/{$id}/open");
        $this->log('push', 'open_thread', ['thread_id' => $id], $resp);

        if (! $resp->successful()) {
            throw new RuntimeException("Channex open thread {$id} failed: HTTP {$resp->status()}");
        }
    }

    /** Fetch one guest-message thread (title = guest name, channel, status). */
    public function getMessageThread(string $id): ?array
    {
        $resp = $this->http(idempotent: true)->get("{$this->baseUrl}/message_threads/{$id}");

        return $resp->successful() ? $resp->json('data') : null;
    }

    /** Send a host reply into a message thread; returns Channex's created message. */
    public function sendThreadMessage(string $threadId, string $message): array
    {
        $payload = ['message' => ['message' => $message]];
        $resp = $this->http()->post("{$this->baseUrl}/message_threads/{$threadId}/messages", $payload);
        $this->log('push', 'send_message', ['thread_id' => $threadId], $resp);

        if (! $resp->successful()) {
            throw new RuntimeException("Channex send message to {$threadId} failed: HTTP {$resp->status()}");
        }

        return $resp->json('data') ?? [];
    }

    /**
     * All guest-message threads of the configured property (paginated until
     * exhausted, capped at $maxPages). Used by the backfill, so existing OTA
     * conversations reach the inbox — the webhook only delivers new ones.
     */
    public function listMessageThreads(int $maxPages = 10, int $limit = 100): array
    {
        $threads = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $resp = $this->http(idempotent: true)->get("{$this->baseUrl}/message_threads", [
                'filter' => ['property_id' => $this->propertyId],
                'pagination' => ['page' => $page, 'limit' => $limit],
            ]);

            if (! $resp->successful()) {
                throw new RuntimeException("Channex GET /message_threads failed: HTTP {$resp->status()}");
            }

            $batch = $resp->json('data') ?? [];
            $threads = array_merge($threads, $batch);
            if (count($batch) < $limit) {
                break;
            }
        }

        return $threads;
    }

    /** Every message of one thread (paginated like listMessageThreads). */
    public function getThreadMessages(string $threadId, int $maxPages = 10, int $limit = 100): array
    {
        $messages = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $resp = $this->http(idempotent: true)->get("{$this->baseUrl}/message_threads/{$threadId}/messages", [
                'pagination' => ['page' => $page, 'limit' => $limit],
            ]);

            if (! $resp->successful()) {
                throw new RuntimeException("Channex GET messages of thread {$threadId} failed: HTTP {$resp->status()}");
            }

            $batch = $resp->json('data') ?? [];
            $messages = array_merge($messages, $batch);
            if (count($batch) < $limit) {
                break;
            }
        }

        return $messages;
    }

    // -- internals --------------------------------------------------------

    protected function http(bool $idempotent = false, int $timeout = 30): PendingRequest
    {
        $req = Http::withHeaders(['user-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout($timeout);

        if (! $idempotent) {
            // Creates are single-shot: a retry could duplicate a room type / rate plan.
            return $req;
        }

        // Idempotent reads retry only TRANSIENT failures (connection error /
        // 5xx). A 4xx is a permanent client error, so retrying wastes time.
        // ARI writes intentionally use one HTTP attempt; their queue jobs own
        // the one-minute retry/backoff policy.
        return $req->retry(3, 250, function (\Throwable $e) {
            return ! ($e instanceof RequestException) || (bool) $e->response?->serverError();
        }, throw: false);
    }

    /**
     * Channex can return HTTP 200 while rejecting one or more ARI rows. Those
     * rejections are reported under meta.warnings and must fail the queue job.
     */
    private function ariAccepted(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $warnings = $response->json('meta.warnings', []);

        return ! is_array($warnings) || $warnings === [];
    }

    /** Best-effort audit row; a logging failure must never break the sync. */
    protected function log(
        string $direction,
        string $action,
        array $request,
        Response $response,
        ?bool $accepted = null,
        ?int $roomTypeId = null,
    ): void {
        $body = $response->json();
        $ok = $accepted ?? $response->successful();

        ChannelSyncLog::record([
            'channel' => 'channex',
            'direction' => $direction,
            'action' => $action,
            'room_type_id' => $roomTypeId,
            'status' => $ok ? 'ok' : 'error',
            'request' => $request,
            'response' => is_array($body) ? $body : ['raw' => $response->body()],
            'error' => $ok
                ? null
                : ($response->successful() ? 'HTTP 200 with Channex ARI warnings' : "HTTP {$response->status()}"),
        ]);
    }
}
