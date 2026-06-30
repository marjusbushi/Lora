<?php

namespace Tests\Feature;

use App\Models\ChannelSyncLog;
use App\Services\ChannexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannexClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Any HTTP call not explicitly faked must throw, never hit the real
        // Channex API (a partial fake otherwise leaks to staging.channex.io).
        Http::preventStrayRequests();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
        ]);
    }

    public function test_not_configured_when_key_missing(): void
    {
        config(['services.channex.api_key' => '']);
        $this->assertFalse((new ChannexClient)->configured());
    }

    public function test_cents_conversion_roundtrips(): void
    {
        $this->assertSame(8000, ChannexClient::toCents(80.0));
        $this->assertSame(99, ChannexClient::toCents(0.99));
        $this->assertSame(1999, ChannexClient::toCents(19.99)); // float-drift rounding
        $this->assertSame(50.0, ChannexClient::fromCents(5000));
    }

    public function test_get_properties_sends_user_api_key_header(): void
    {
        Http::fake(['*properties*' => Http::response(['data' => [
            ['id' => 'P1', 'attributes' => ['title' => 'Villa Mucho']],
        ]])]);

        $props = (new ChannexClient)->getProperties();

        $this->assertSame('Villa Mucho', $props[0]['attributes']['title']);
        Http::assertSent(fn ($r) => $r->hasHeader('user-api-key', 'test-key') && str_contains($r->url(), '/properties'));
    }

    public function test_create_room_type_posts_wrapped_payload_and_returns_id(): void
    {
        Http::fake(['*room_types*' => Http::response(['data' => ['id' => 'RT-9']], 201)]);

        $id = (new ChannexClient)->createRoomType('Studio', 4, 3);

        $this->assertSame('RT-9', $id);
        Http::assertSent(function ($r) {
            $rt = $r->data()['room_type'] ?? [];

            return ($rt['title'] ?? null) === 'Studio'
                && (int) ($rt['count_of_rooms'] ?? 0) === 4
                && (int) ($rt['occ_adults'] ?? 0) === 3
                && (int) ($rt['default_occupancy'] ?? 0) === 3
                && ($rt['property_id'] ?? null) === 'PROP-1';
        });
    }

    public function test_create_rate_plan_uses_single_per_room_option(): void
    {
        Http::fake(['*rate_plans*' => Http::response(['data' => ['id' => 'RP-1']], 201)]);

        $id = (new ChannexClient)->createRatePlan('RT-9', 2);

        $this->assertSame('RP-1', $id);
        Http::assertSent(function ($r) {
            $rp = $r->data()['rate_plan'] ?? [];

            return ($rp['sell_mode'] ?? null) === 'per_room'
                && count($rp['options'] ?? []) === 1
                && (int) ($rp['options'][0]['occupancy'] ?? 0) === 2
                && ($rp['options'][0]['is_primary'] ?? null) === true;
        });
    }

    public function test_push_rate_converts_euros_to_cents(): void
    {
        Http::fake(['*restrictions*' => Http::response(['data' => [['id' => 't', 'type' => 'task']]])]);

        $ok = (new ChannexClient)->pushRate('RP-1', '2026-07-01', '2026-07-10', 80.0);

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => (int) $r->data()['values'][0]['rate'] === 8000);
    }

    public function test_push_availability_sends_count(): void
    {
        Http::fake(['*availability*' => Http::response(['data' => [['id' => 't', 'type' => 'task']]])]);

        $ok = (new ChannexClient)->pushAvailability('RT-9', '2026-07-01', '2026-07-10', 5);

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => (int) $r->data()['values'][0]['availability'] === 5);
    }

    public function test_push_writes_an_ok_channel_sync_log(): void
    {
        Http::fake(['*restrictions*' => Http::response(['data' => []])]);

        (new ChannexClient)->pushRate('RP-1', '2026-07-01', '2026-07-10', 50.0);

        $log = ChannelSyncLog::where('channel', 'channex')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('rate', $log->action);
        $this->assertSame('push', $log->direction);
        $this->assertSame('ok', $log->status);
    }

    public function test_failed_push_returns_false_and_logs_error(): void
    {
        Http::fake(['*restrictions*' => Http::response(['errors' => ['nope']], 422)]);

        $ok = (new ChannexClient)->pushRate('RP-1', '2026-07-01', '2026-07-10', 50.0);

        $this->assertFalse($ok);
        $log = ChannelSyncLog::where('channel', 'channex')->latest('id')->first();
        $this->assertSame('error', $log->status);
        $this->assertSame('HTTP 422', $log->error);
    }

    public function test_read_throws_on_auth_failure_instead_of_returning_empty(): void
    {
        // A bad key (401) must surface, not look like an empty account.
        Http::fake(['*properties*' => Http::response(['errors' => ['unauthorized']], 401)]);

        $this->expectException(\RuntimeException::class);
        (new ChannexClient)->getProperties();
    }

    public function test_idempotent_read_retries_a_transient_5xx(): void
    {
        Http::fake(['*properties*' => Http::sequence()
            ->push(['error' => 'boom'], 503)
            ->push(['data' => [['id' => 'P1', 'attributes' => ['title' => 'OK']]]], 200)]);

        $props = (new ChannexClient)->getProperties();

        $this->assertSame('P1', $props[0]['id']);
        Http::assertSentCount(2); // retried the 503, then succeeded
    }

    public function test_create_is_single_shot_not_retried_on_failure(): void
    {
        // The load-bearing invariant: a create must NOT retry (would duplicate).
        Http::fake(['*room_types*' => Http::response(['error' => 'boom'], 500)]);

        $id = (new ChannexClient)->createRoomType('Studio', 4, 3);

        $this->assertNull($id);
        Http::assertSentCount(1);
    }

    public function test_create_room_type_failure_returns_null_and_logs_error(): void
    {
        Http::fake(['*room_types*' => Http::response(['errors' => ['nope']], 422)]);

        $id = (new ChannexClient)->createRoomType('Studio', 4, 3);

        $this->assertNull($id);
        $log = ChannelSyncLog::where('channel', 'channex')->where('action', 'create_room_type')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('error', $log->status);
    }

    public function test_audit_log_captures_request_and_response_payloads(): void
    {
        Http::fake(['*restrictions*' => Http::response(['data' => [['id' => 'task-1', 'type' => 'task']]])]);

        (new ChannexClient)->pushRate('RP-1', '2026-07-01', '2026-07-10', 80.0);

        $log = ChannelSyncLog::where('channel', 'channex')->latest('id')->first();
        $this->assertSame(8000, $log->request['values'][0]['rate']); // request body kept (in cents)
        $this->assertIsArray($log->response);
        $this->assertSame('task-1', $log->response['data'][0]['id']); // response body kept
    }
}
