<?php

namespace Tests\Feature;

use App\Events\GuestTyping;
use App\Events\MessageReceived;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Treguesi "po shkruan…" (task #344): ngjarja e prezencës nga ura transmetohet
 * te inbox-i i hapur PA asnjë shkrim në DB — dhe kurrë për biseda OTA.
 */
class RealtimeTypingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        config(['services.whatsapp_bridge.token' => 'bridge-secret']);
        Queue::fake(); // job-i i AI-t s'ekzekutohet
        Event::fake([GuestTyping::class, MessageReceived::class]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function postBridgeEvent(string $type, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/whatsapp/bridge/event', [
            'tenant_id' => $this->tenant->id,
            'type' => $type,
            'payload' => $payload,
        ], ['Authorization' => 'Bearer bridge-secret']);
    }

    /** Bisedë WhatsApp reale: krijohet nga rruga e vërtetë e importit. */
    private function makeWhatsAppThread(): MessageThread
    {
        $this->postBridgeEvent('message', [
            'jid' => '355691234567@s.whatsapp.net',
            'phone' => '355691234567',
            'message_id' => 'WA-TYPING-SEED',
            'name' => 'Guest Typing',
            'body' => 'Pershendetje',
            'timestamp' => now()->timestamp,
        ])->assertOk();

        return MessageThread::query()->where('whatsapp_jid', '355691234567@s.whatsapp.net')->sole();
    }

    public function test_composing_broadcasts_guest_typing_without_touching_db(): void
    {
        $thread = $this->makeWhatsAppThread();
        $messagesBefore = Message::query()->count();
        $unreadBefore = $thread->unread_count;

        $this->postBridgeEvent('presence', [
            'jid' => '355691234567@s.whatsapp.net',
            'state' => 'composing',
            'timestamp' => now()->timestamp,
        ])->assertOk();

        Event::assertDispatched(GuestTyping::class, fn ($e) => $e->tenantId === $this->tenant->id
            && $e->threadId === $thread->id
            && $e->state === 'composing');

        // Asnjë shkrim: as mesazh i ri, as unread i rritur (kriteri 3).
        $this->assertSame($messagesBefore, Message::query()->count());
        $this->assertSame($unreadBefore, $thread->fresh()->unread_count);
    }

    public function test_recording_and_paused_pass_through_as_states(): void
    {
        $thread = $this->makeWhatsAppThread();

        $this->postBridgeEvent('presence', [
            'jid' => '355691234567@s.whatsapp.net',
            'state' => 'recording',
        ])->assertOk();
        $this->postBridgeEvent('presence', [
            'jid' => '355691234567@s.whatsapp.net',
            'state' => 'paused',
        ])->assertOk();

        Event::assertDispatched(GuestTyping::class, fn ($e) => $e->threadId === $thread->id && $e->state === 'recording');
        Event::assertDispatched(GuestTyping::class, fn ($e) => $e->threadId === $thread->id && $e->state === 'paused');
    }

    public function test_unknown_jid_and_ota_threads_never_broadcast(): void
    {
        // Bisedë OTA (Channex) — s'ka whatsapp_jid, s'gjendet dot nga prezenca.
        MessageThread::create([
            'channex_thread_id' => 'TH-OTA-1',
            'channel' => 'booking.com',
            'guest_name' => 'OTA Guest',
            'status' => 'open',
        ]);

        $this->postBridgeEvent('presence', [
            'jid' => '355689999999@s.whatsapp.net', // s'i përket asnjë bisede
            'state' => 'composing',
        ])->assertOk();

        Event::assertNotDispatched(GuestTyping::class);
    }

    public function test_invalid_state_or_missing_jid_is_ignored(): void
    {
        $this->makeWhatsAppThread();

        $this->postBridgeEvent('presence', [
            'jid' => '355691234567@s.whatsapp.net',
            'state' => 'available', // s'është tregues shkrimi
        ])->assertOk();
        $this->postBridgeEvent('presence', ['state' => 'composing'])->assertOk();

        Event::assertNotDispatched(GuestTyping::class);
    }
}
