<?php

namespace Tests\Feature;

use App\Events\MessageReceived;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mesazhet live (task #343): eventi MessageReceived emetohet nga TË DY
 * importuesit (WhatsApp + Channex) pas commit-it — dhe KURRË për dublikata.
 */
class RealtimeMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        config(['services.whatsapp_bridge.token' => 'bridge-secret']);
        Queue::fake(); // job-i i AI s'ekzekutohet — testohet vetëm emetimi
        Event::fake([MessageReceived::class]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_whatsapp_incoming_message_broadcasts_message_received(): void
    {
        $this->postJson('/whatsapp/bridge/event', [
            'tenant_id' => $this->tenant->id,
            'type' => 'message',
            'payload' => [
                'jid' => '355691234567@s.whatsapp.net',
                'phone' => '355691234567',
                'message_id' => 'WA-RT-1',
                'name' => 'Guest RT',
                'body' => 'Live?',
                'timestamp' => now()->timestamp,
            ],
        ], ['Authorization' => 'Bearer bridge-secret'])->assertOk();

        $thread = MessageThread::query()->sole();
        Event::assertDispatched(MessageReceived::class, fn ($e) => $e->tenantId === $this->tenant->id && $e->threadId === $thread->id);

        // Dublikata: asnjë emetim i dytë.
        $this->postJson('/whatsapp/bridge/event', [
            'tenant_id' => $this->tenant->id,
            'type' => 'message',
            'payload' => [
                'jid' => '355691234567@s.whatsapp.net',
                'phone' => '355691234567',
                'message_id' => 'WA-RT-1',
                'name' => 'Guest RT',
                'body' => 'Live?',
                'timestamp' => now()->timestamp,
            ],
        ], ['Authorization' => 'Bearer bridge-secret'])->assertOk();

        Event::assertDispatchedTimes(MessageReceived::class, 1);
    }

    public function test_channex_webhook_message_broadcasts_message_received(): void
    {
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
            'services.channex.webhook_secret' => 'topsecret',
        ]);
        Http::fake([
            'https://staging.channex.io/api/v1/message_threads/*' => Http::response(['data' => ['attributes' => [
                'title' => 'John Guest', 'channel' => 'booking.com', 'status' => 'open', 'property_id' => 'PROP-1',
            ]]], 200),
            'https://staging.channex.io/api/v1/bookings/*' => Http::response(['data' => [
                'id' => 'BK-1', 'attributes' => ['ota_reservation_code' => 'BK-REF'],
            ]], 200),
        ]);

        $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => [
            'id' => 'MSG-RT-1',
            'message' => 'Live nga Booking?',
            'sender' => 'guest',
            'property_id' => 'PROP-1',
            'booking_id' => 'BK-1',
            'message_thread_id' => 'TH-RT-1',
            'have_attachment' => false,
        ]], ['X-Channex-Webhook-Secret' => 'topsecret'])->assertOk();

        $thread = MessageThread::query()->sole();
        Event::assertDispatched(MessageReceived::class, fn ($e) => $e->tenantId === $this->tenant->id && $e->threadId === $thread->id);
    }
}
