<?php

namespace Tests\Feature;

use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppBridgeClient;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WhatsApp QR-lite (task #335): endpoint-i i ngjarjeve të urës + veprimet e
 * panelit. Ura vetë mock-ohet — testohet kontrata e Laravel-it.
 */
class WhatsAppBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        config(['services.whatsapp_bridge.token' => 'bridge-secret']);

        // Importuesi dispatch-on GenerateAiGuestReply (afterCommit EKZEKUTOHET
        // nën RefreshDatabase) — pa këtë mock, .env lokal me çelës Gemini bën
        // thirrje REALE në teste. Testet e AI e ri-mock-ojnë sipas nevojës.
        $this->mock(\App\Services\GeminiClient::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(false));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function messageEvent(array $override = []): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'type' => 'message',
            'payload' => array_merge([
                'jid' => '355691234567@s.whatsapp.net',
                'message_id' => 'WA-MSG-1',
                'name' => 'Guest WA',
                'body' => 'A keni dhoma të lira?',
                'timestamp' => now()->timestamp,
            ], $override),
        ];
    }

    public function test_wrong_token_is_403(): void
    {
        $this->postJson('/whatsapp/bridge/event', $this->messageEvent(),
            ['Authorization' => 'Bearer wrong'])->assertForbidden();

        $this->assertSame(0, MessageThread::query()->count());
    }

    public function test_unconfigured_token_disables_the_endpoint_entirely(): void
    {
        config(['services.whatsapp_bridge.token' => '']);

        $this->postJson('/whatsapp/bridge/event', $this->messageEvent(),
            ['Authorization' => 'Bearer '])->assertForbidden();
    }

    public function test_tenant_mismatch_is_rejected_without_fallback(): void
    {
        $event = $this->messageEvent();
        $event['tenant_id'] = $this->tenant->id + 999;

        $this->postJson('/whatsapp/bridge/event', $event,
            ['Authorization' => 'Bearer bridge-secret'])->assertStatus(422);

        $this->assertSame(0, MessageThread::query()->count());
    }

    public function test_incoming_message_creates_thread_and_message_with_dedup(): void
    {
        foreach (range(1, 2) as $_) {
            $this->postJson('/whatsapp/bridge/event', $this->messageEvent(),
                ['Authorization' => 'Bearer bridge-secret'])->assertOk();
        }

        $thread = MessageThread::query()->sole();
        $this->assertSame('whatsapp', $thread->channel);
        $this->assertSame('355691234567@s.whatsapp.net', $thread->whatsapp_jid);
        $this->assertSame('Guest WA', $thread->guest_name);
        $this->assertSame(1, $thread->unread_count);
        $this->assertSame(1, $thread->messages()->count());
        $this->assertSame('A keni dhoma të lira?', $thread->messages()->sole()->body);
    }

    public function test_new_message_clears_stale_ai_flags(): void
    {
        $this->postJson('/whatsapp/bridge/event', $this->messageEvent(),
            ['Authorization' => 'Bearer bridge-secret'])->assertOk();

        $thread = MessageThread::query()->sole();
        $thread->forceFill(['ai_suggestion' => 'Draft i vjetër', 'ai_unanswered_question' => 'Pyetje e vjetër'])->save();

        $this->postJson('/whatsapp/bridge/event', $this->messageEvent(['message_id' => 'WA-MSG-2', 'body' => 'Dhe çmimi?']),
            ['Authorization' => 'Bearer bridge-secret'])->assertOk();

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertNull($thread->ai_unanswered_question);
        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_status_event_upserts_the_connection_row(): void
    {
        $this->postJson('/whatsapp/bridge/event', [
            'tenant_id' => $this->tenant->id,
            'type' => 'status',
            'payload' => ['status' => 'connected', 'phone' => '355691234567'],
        ], ['Authorization' => 'Bearer bridge-secret'])->assertOk();

        $row = WhatsAppConnection::query()->sole();
        $this->assertSame('connected', $row->status);
        $this->assertSame('355691234567', $row->phone_number);
    }

    public function test_admin_connect_marks_pairing_and_bridge_failure_is_a_soft_error(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturn(['status' => 'pairing']);
        });

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.connect'))
            ->assertRedirect();

        $this->assertSame('pairing', WhatsAppConnection::query()->sole()->status);

        // Ura offline → gabim i qetë, pa 500 dhe pa ndryshim statusi të ri.
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andThrow(new \RuntimeException('Ura nuk përgjigjet'));
        });

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.connect'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_status_endpoint_reports_bridge_offline_without_500(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('status')->once()->andThrow(new \RuntimeException('offline'));
        });

        $this->actingAs($admin)
            ->getJson(route('settings.whatsapp.status'))
            ->assertOk()
            ->assertJson(['bridge_offline' => true, 'status' => 'disconnected']);
    }

    public function test_disconnect_clears_the_connection_even_when_bridge_is_down(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        WhatsAppConnection::create(['status' => 'connected', 'phone_number' => '35569000']);

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('logout')->once()->andThrow(new \RuntimeException('offline'));
        });

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.disconnect'))
            ->assertRedirect();

        $row = WhatsAppConnection::query()->sole();
        $this->assertSame('disconnected', $row->status);
        $this->assertNull($row->phone_number);
    }

    private function makeWhatsAppThread(): MessageThread
    {
        return MessageThread::create([
            'whatsapp_jid' => '355691234567@s.whatsapp.net',
            'channel' => 'whatsapp',
            'guest_name' => 'Guest WA',
            'status' => 'open',
        ]);
    }

    public function test_operator_reply_on_whatsapp_thread_sends_via_bridge(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $thread = $this->makeWhatsAppThread();

        $this->mock(WhatsAppBridgeClient::class, function ($mock) use ($thread) {
            $mock->shouldReceive('send')->once()
                ->with($this->tenant->id, $thread->whatsapp_jid, 'Po, kemi dhoma të lira.')
                ->andReturn(['id' => 'WA-SENT-1']);
        });
        $this->mock(\App\Services\ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->actingAs($admin)
            ->post(route('messages.reply', $thread), ['body' => 'Po, kemi dhoma të lira.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('messages', [
            'message_thread_id' => $thread->id,
            'sender' => \App\Models\Message::SENDER_HOST,
            'whatsapp_message_id' => 'WA-SENT-1',
            'body' => 'Po, kemi dhoma të lira.',
        ]);
    }

    public function test_bridge_failure_on_reply_records_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $thread = $this->makeWhatsAppThread();

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Ura nuk përgjigjet'));
        });

        $this->actingAs($admin)
            ->post(route('messages.reply', $thread), ['body' => 'Provë'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, $thread->messages()->count());
    }

    public function test_learning_loop_works_on_whatsapp_threads_too(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $thread = $this->makeWhatsAppThread();
        $thread->forceFill(['ai_unanswered_question' => 'A ofroni masazhe?'])->save();

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['id' => 'WA-SENT-2']);
        });

        $this->actingAs($admin)
            ->post(route('messages.reply', $thread), ['body' => 'Po, çdo ditë në spa.'])
            ->assertRedirect();

        $this->assertDatabaseHas('hotel_faq_suggestions', [
            'question' => 'A ofroni masazhe?',
            'suggested_answer' => 'Po, çdo ditë në spa.',
            'status' => \App\Models\HotelFaqSuggestion::STATUS_PENDING,
        ]);
        $this->assertNull($thread->refresh()->ai_unanswered_question);
    }

    private function fakeGemini(bool $confident, string $reply = 'Përgjigje AI.'): void
    {
        $this->mock(\App\Services\GeminiClient::class, function ($mock) use ($confident, $reply) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('structured')->andReturn(['confident' => $confident, 'reply' => $reply]);
        });
    }

    private function runAiJob(MessageThread $thread, int $messageId): void
    {
        app()->call([new \App\Jobs\GenerateAiGuestReply($thread->id, $messageId), 'handle']);
    }

    public function test_whatsapp_auto_reply_default_off_leaves_draft_only(): void
    {
        \App\Models\HotelFaq::create(['question' => 'Parkim?', 'answer' => 'Po.']);
        $thread = $this->makeWhatsAppThread();
        $message = $thread->messages()->create([
            'sender' => \App\Models\Message::SENDER_GUEST,
            'body' => 'A keni parkim?',
            'sent_at' => now(),
        ]);

        $this->fakeGemini(true);
        $this->mock(WhatsAppBridgeClient::class, fn ($mock) => $mock->shouldReceive('send')->never());

        $this->runAiJob($thread, $message->id);

        // Confident + FAQ + çelësi OTA ndezur — por whatsapp ka çelësin e VET,
        // default FIKUR → vetëm draft, kurrë dërgim.
        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_whatsapp_auto_reply_sends_via_bridge_when_its_own_switch_is_on(): void
    {
        \App\Models\Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
        \App\Models\HotelFaq::create(['question' => 'Parkim?', 'answer' => 'Po.']);
        $thread = $this->makeWhatsAppThread();
        $message = $thread->messages()->create([
            'sender' => \App\Models\Message::SENDER_GUEST,
            'body' => 'A keni parkim?',
            'sent_at' => now(),
        ]);

        $this->fakeGemini(true);
        $this->mock(WhatsAppBridgeClient::class, function ($mock) use ($thread) {
            $mock->shouldReceive('send')->once()
                ->with($this->tenant->id, $thread->whatsapp_jid, 'Përgjigje AI.')
                ->andReturn(['id' => 'WA-AI-1']);
        });
        $this->mock(\App\Services\ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runAiJob($thread, $message->id);

        $this->assertDatabaseHas('messages', [
            'message_thread_id' => $thread->id,
            'sender' => \App\Models\Message::SENDER_HOST,
            'sent_by_ai' => true,
            'whatsapp_message_id' => 'WA-AI-1',
        ]);
        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    public function test_whatsapp_bridge_failure_on_auto_reply_falls_back_to_draft(): void
    {
        \App\Models\Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
        \App\Models\HotelFaq::create(['question' => 'Parkim?', 'answer' => 'Po.']);
        $thread = $this->makeWhatsAppThread();
        $message = $thread->messages()->create([
            'sender' => \App\Models\Message::SENDER_GUEST,
            'body' => 'A keni parkim?',
            'sent_at' => now(),
        ]);

        $this->fakeGemini(true);
        $this->mock(WhatsAppBridgeClient::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Ura offline')));

        $this->runAiJob($thread, $message->id);

        $thread->refresh();
        $this->assertSame('Përgjigje AI.', $thread->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_incoming_whatsapp_event_dispatches_the_ai_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->postJson('/whatsapp/bridge/event', $this->messageEvent(),
            ['Authorization' => 'Bearer bridge-secret'])->assertOk();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateAiGuestReply::class, 1);

        // Dublikata NUK e ri-dispatch-on job-in.
        $this->postJson('/whatsapp/bridge/event', $this->messageEvent(),
            ['Authorization' => 'Bearer bridge-secret'])->assertOk();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateAiGuestReply::class, 1);
    }

    public function test_db_unique_index_blocks_duplicate_whatsapp_message_even_past_the_exists_check(): void
    {
        // Gara e dy dorëzimeve paralele s'simulohet dot në një proces — provohet
        // shtresa e fundit e mbrojtjes: vetë indeksi unik i DB-së.
        $thread = $this->makeWhatsAppThread();
        $attrs = [
            'whatsapp_message_id' => 'WA-DUP-1',
            'sender' => \App\Models\Message::SENDER_GUEST,
            'body' => 'Provë',
            'sent_at' => now(),
        ];
        $thread->messages()->create($attrs);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $thread->messages()->create($attrs);
    }

    public function test_user_without_settings_permission_cannot_connect(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $receptionist = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $receptionist->assignRole('receptionist');

        $this->actingAs($receptionist)
            ->post(route('settings.whatsapp.connect'))
            ->assertForbidden();
    }
}
