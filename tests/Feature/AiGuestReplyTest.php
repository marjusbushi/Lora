<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\HotelFaq;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\ChannexClient;
use App\Services\GeminiClient;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiGuestReplyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant-i legacy nga migrimet — ka messages të trashëguar (falas) + CM.
        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function makeThreadWithGuestMessage(): array
    {
        $thread = MessageThread::create([
            'channex_thread_id' => 'thr-'.uniqid(),
            'channel' => 'booking.com',
            'guest_name' => 'Guest Test',
            'status' => 'open',
        ]);
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => 'What time is breakfast?',
            'sent_at' => now(),
        ]);

        return [$thread, $message];
    }

    private function fakeGemini(bool $confident, string $reply = 'Breakfast is 7-10.'): void
    {
        $this->mock(GeminiClient::class, function ($mock) use ($confident, $reply) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('structured')->andReturn(['confident' => $confident, 'reply' => $reply]);
        });
    }

    private function runJob(MessageThread $thread, Message $message): void
    {
        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);
    }

    public function test_confident_with_faq_and_auto_on_sends_labeled_ai_reply(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10 every day.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, function ($mock) use ($thread) {
            $mock->shouldReceive('sendThreadMessage')->once()
                ->with($thread->channex_thread_id, 'Breakfast is 7-10.');
        });

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertDatabaseHas('messages', [
            'message_thread_id' => $thread->id,
            'sender' => Message::SENDER_HOST,
            'sent_by_ai' => true,
            'body' => 'Breakfast is 7-10.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_reply']);
    }

    public function test_unconfident_leaves_draft_only(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(false, 'Recepsioni do t\'ju përgjigjet shumë shpejt.');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertSame('Recepsioni do t\'ju përgjigjet shumë shpejt.', $thread->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_empty_faq_never_auto_sends_even_when_confident(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    public function test_auto_off_leaves_draft_only(): void
    {
        Setting::set('ai_mcp.guest_auto_reply_enabled', false, 'boolean');
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    public function test_guest_reply_disabled_is_full_noop(): void
    {
        Setting::set('ai_mcp.guest_reply_enabled', false, 'boolean');
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('structured')->never());
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    public function test_staff_already_replied_is_noop(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $thread->messages()->create([
            'sender' => Message::SENDER_HOST,
            'body' => 'Staff answered already.',
            'sent_at' => now()->addSecond(),
        ]);

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    public function test_rate_limit_stops_the_sixth_reply_in_an_hour(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        Cache::put(sprintf('ai-guest-reply:%d:%d', $this->tenant->id, $thread->id), 5, now()->addHour());

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    public function test_staff_reply_clears_the_ai_draft(): void
    {
        [$thread] = $this->makeThreadWithGuestMessage();
        $thread->forceFill(['ai_suggestion' => 'Draft AI', 'ai_suggested_at' => now()])->save();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = \App\Models\User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->actingAs($admin)
            ->post(route('messages.reply', $thread), ['body' => 'Staff reply'])
            ->assertRedirect();

        $this->assertNull($thread->refresh()->ai_suggestion);
    }
}
