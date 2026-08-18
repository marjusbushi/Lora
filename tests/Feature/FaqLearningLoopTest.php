<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\HotelFaq;
use App\Models\HotelFaqSuggestion;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChannexClient;
use App\Services\GeminiClient;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cikli i mësimit të Lora AI (task #334): pyetje pa përgjigje → përgjigjja e
 * stafit → sugjerim FAQ → ruajtje/hedhje nga pronari.
 */
class FaqLearningLoopTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function makeThreadWithGuestMessage(string $body = 'A ofroni masazhe?'): array
    {
        $thread = MessageThread::create([
            'channex_thread_id' => 'thr-'.uniqid(),
            'channel' => 'booking.com',
            'guest_name' => 'Guest Test',
            'status' => 'open',
        ]);
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => $body,
            'sent_at' => now(),
        ]);

        return [$thread, $message];
    }

    private function fakeGemini(bool $confident, string $reply = 'Përgjigje AI.'): void
    {
        $this->mock(GeminiClient::class, function ($mock) use ($confident, $reply) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')
                ->andReturn(['args' => ['confident' => $confident, 'reply' => $reply], 'toolsUsed' => []]);
        });
    }

    private function runJob(MessageThread $thread, Message $message): void
    {
        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);
    }

    public function test_unconfident_reply_records_the_unanswered_question(): void
    {
        HotelFaq::create(['question' => 'Parkim?', 'answer' => 'Po.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage('A ofroni masazhe?');

        $this->fakeGemini(false);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertSame('A ofroni masazhe?', $thread->refresh()->ai_unanswered_question);
    }

    public function test_confident_with_auto_off_does_not_record_unanswered(): void
    {
        // Lora e DINTE (confident) — auto-off e mban si draft, por s'ka ç'mësohet.
        Setting::set('ai_mcp.guest_auto_reply_enabled', false, 'boolean');
        HotelFaq::create(['question' => 'Parkim?', 'answer' => 'Po.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNotNull($thread->ai_suggestion);
        $this->assertNull($thread->ai_unanswered_question);
    }

    public function test_successful_auto_reply_clears_stale_unanswered_flag(): void
    {
        HotelFaq::create(['question' => 'Parkim?', 'answer' => 'Po.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $thread->forceFill(['ai_unanswered_question' => 'Pyetje e vjetër'])->save();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once()->andReturn(['id' => 'chx-1']));

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_unanswered_question);
    }

    public function test_staff_reply_turns_the_pair_into_a_pending_suggestion(): void
    {
        [$thread] = $this->makeThreadWithGuestMessage();
        $thread->forceFill(['ai_unanswered_question' => 'A ofroni masazhe?'])->save();

        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->actingAs($this->admin)
            ->post(route('messages.reply', $thread), ['body' => 'Po, çdo ditë 10:00-18:00 në spa.'])
            ->assertRedirect();

        $this->assertDatabaseHas('hotel_faq_suggestions', [
            'message_thread_id' => $thread->id,
            'question' => 'A ofroni masazhe?',
            'suggested_answer' => 'Po, çdo ditë 10:00-18:00 në spa.',
            'status' => HotelFaqSuggestion::STATUS_PENDING,
        ]);
        $this->assertNull($thread->refresh()->ai_unanswered_question);
    }

    public function test_duplicate_pending_question_is_not_created_twice(): void
    {
        HotelFaqSuggestion::create(['question' => 'A ofroni masazhe?', 'suggested_answer' => 'E para.']);

        [$thread] = $this->makeThreadWithGuestMessage();
        $thread->forceFill(['ai_unanswered_question' => 'A ofroni masazhe?'])->save();

        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->actingAs($this->admin)
            ->post(route('messages.reply', $thread), ['body' => 'E dyta.'])
            ->assertRedirect();

        $this->assertSame(1, HotelFaqSuggestion::query()->count());
        $this->assertDatabaseHas('hotel_faq_suggestions', ['suggested_answer' => 'E para.']);
    }

    public function test_accepting_a_suggestion_creates_the_faq_with_edited_text(): void
    {
        $suggestion = HotelFaqSuggestion::create(['question' => 'masazhe?', 'suggested_answer' => 'po']);

        $this->actingAs($this->admin)
            ->post(route('settings.faqs.suggestions.accept', $suggestion), [
                'question' => 'A ofroni masazhe?',
                'answer' => 'Po, çdo ditë në spa.',
            ])->assertRedirect();

        $this->assertDatabaseHas('hotel_faqs', [
            'question' => 'A ofroni masazhe?',
            'answer' => 'Po, çdo ditë në spa.',
            'is_active' => true,
        ]);
        $this->assertSame(HotelFaqSuggestion::STATUS_SAVED, $suggestion->refresh()->status);

        // I trajtuar tashmë — s'pranohet dy herë (s'krijohet FAQ e dytë).
        $this->actingAs($this->admin)
            ->post(route('settings.faqs.suggestions.accept', $suggestion), [
                'question' => 'Tjetër', 'answer' => 'Tjetër',
            ])->assertRedirect();
        $this->assertSame(1, HotelFaq::query()->count());
    }

    public function test_dismissing_a_suggestion_marks_it_dismissed(): void
    {
        $suggestion = HotelFaqSuggestion::create(['question' => 'X?', 'suggested_answer' => 'Y']);

        $this->actingAs($this->admin)
            ->post(route('settings.faqs.suggestions.dismiss', $suggestion))
            ->assertRedirect();

        $this->assertSame(HotelFaqSuggestion::STATUS_DISMISSED, $suggestion->refresh()->status);
        $this->assertSame(0, HotelFaq::query()->count());
    }

    public function test_new_guest_webhook_message_clears_the_stale_unanswered_flag(): void
    {
        // Mysafiri dërgon mesazh pasues PARA se stafi të përgjigjet: flamuri i
        // pyetjes së kaluar duhet të pastrohet nga importuesi — përndryshe
        // përgjigjja e stafit çiftohet me pyetjen e GABUAR (gjetje Codex #434).
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
            'services.channex.webhook_secret' => 'topsecret',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'https://staging.channex.io/api/v1/message_threads/*' => \Illuminate\Support\Facades\Http::response(['data' => ['attributes' => [
                'title' => 'John Guest', 'channel' => 'booking.com', 'status' => 'open', 'property_id' => 'PROP-1',
            ]]], 200),
            'https://staging.channex.io/api/v1/bookings/*' => \Illuminate\Support\Facades\Http::response(['data' => [
                'id' => 'BK-1', 'attributes' => ['ota_reservation_code' => 'BK-REF'],
            ]], 200),
        ]);

        // Gemini "i pakonfiguruar": job-i i ri (afterCommit ekzekutohet edhe nën
        // RefreshDatabase) del herët — testi mat VETËM pastrimin e importuesit.
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(false));

        [$thread] = $this->makeThreadWithGuestMessage('A ofroni masazhe?');
        $thread->forceFill([
            'channex_thread_id' => 'TH-1',
            'ai_unanswered_question' => 'A ofroni masazhe?',
        ])->save();

        $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => [
            'id' => 'MSG-2',
            'message' => 'Dhe sauna?',
            'sender' => 'guest',
            'property_id' => 'PROP-1',
            'booking_id' => 'BK-1',
            'message_thread_id' => 'TH-1',
            'have_attachment' => false,
        ]], ['X-Channex-Webhook-Secret' => 'topsecret'])->assertOk();

        $this->assertNull($thread->refresh()->ai_unanswered_question);
    }

    public function test_accept_after_dismiss_is_refused_and_creates_no_faq(): void
    {
        $suggestion = HotelFaqSuggestion::create(['question' => 'X?', 'suggested_answer' => 'Y']);
        $suggestion->update(['status' => HotelFaqSuggestion::STATUS_DISMISSED]);

        $this->actingAs($this->admin)
            ->post(route('settings.faqs.suggestions.accept', $suggestion), [
                'question' => 'X?', 'answer' => 'Y',
            ])->assertRedirect();

        $this->assertSame(0, HotelFaq::query()->count());
        $this->assertSame(HotelFaqSuggestion::STATUS_DISMISSED, $suggestion->refresh()->status);
    }

    public function test_cross_tenant_suggestion_is_404(): void
    {
        $other = Tenant::factory()->create();
        app(TenantContext::class)->set($other);
        $foreign = HotelFaqSuggestion::create(['question' => 'E huaja?', 'suggested_answer' => 'Sekret']);
        app(TenantContext::class)->set($this->tenant);

        $this->actingAs($this->admin)
            ->post('/pms/settings/faqs/suggestions/'.$foreign->id.'/accept', [
                'question' => 'Hack', 'answer' => 'Hack',
            ])->assertNotFound();

        app(TenantContext::class)->set($other);
        $this->assertSame(HotelFaqSuggestion::STATUS_PENDING, $foreign->refresh()->status);
    }
}
