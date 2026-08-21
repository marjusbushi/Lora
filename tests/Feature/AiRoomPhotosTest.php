<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\GeminiClient;
use App\Services\WhatsAppBridgeClient;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Fotot e tipologjive nga Lora (task #396): mysafiri kërkon foto → executor-i
 * i MBLEDH (kurrë s'i dërgon në raundin e mjetit) → dalin nga sendAutoReply
 * VETËM pasi përgjigja kalon çdo portë — para tekstit final.
 */
class AiRoomPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function typeWithPhotos(int $count = 2): RoomType
    {
        $type = RoomType::create(['name' => 'Deluxe With Sea View', 'base_price' => 99, 'max_occupancy' => 2, 'amenities' => []]);
        foreach (range(1, $count) as $i) {
            RoomTypeImage::create(['room_type_id' => $type->id, 'path' => "room-types/deluxe-{$i}.jpg", 'sort_order' => $i]);
        }

        return $type;
    }

    private function whatsappThread(): array
    {
        $thread = MessageThread::create([
            'channel' => 'whatsapp',
            'whatsapp_jid' => '355691234567@s.whatsapp.net',
            'guest_name' => 'Andi Guest',
            'status' => 'open',
        ]);
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => 'Ke foto per deluxe sea view?',
            'sent_at' => now(),
        ]);

        return [$thread, $message];
    }

    public function test_photos_are_sent_before_the_text_after_all_gates(): void
    {
        $this->typeWithPhotos(2);
        [$thread, $message] = $this->whatsappThread();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $photos = $executors['send_room_photos'](['room_type' => 'Deluxe With Sea View']);
                if (isset($photos['error'])) {
                    throw new \RuntimeException('photos error: '.$photos['error']);
                }

                return [
                    'args' => ['confident' => true, 'reply' => 'Ja fotot e Deluxe With Sea View — më thoni si ju duken!', 'kind' => 'informative'],
                    'toolsUsed' => ['send_room_photos'],
                ];
            });
        });

        $order = [];
        $this->mock(WhatsAppBridgeClient::class, function ($mock) use (&$order) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('sendImage')->twice()
                ->withArgs(function ($tenantId, $jid, $url, $caption) use (&$order) {
                    $order[] = 'image';

                    return $jid === '355691234567@s.whatsapp.net'
                        && str_contains($url, '/storage/room-types/deluxe-');
                })
                ->andReturnUsing(fn () => ['id' => 'wa-img-'.uniqid('', true)]);
            $mock->shouldReceive('send')->once()
                ->withArgs(function () use (&$order) {
                    $order[] = 'text';

                    return true;
                })
                ->andReturn(['id' => 'wa-txt-1']);
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        // Fotot PARA tekstit; rreshtat Message për të dyja + auditimi.
        $this->assertSame(['image', 'image', 'text'], $order);
        $this->assertSame(2, Message::query()->where('message_thread_id', $thread->id)->where('body', 'like', '📷%')->count());
        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true, 'body' => 'Ja fotot e Deluxe With Sea View — më thoni si ju duken!']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_photos_sent', 'source' => 'ai']);
    }

    public function test_ota_thread_never_declares_the_photo_tool(): void
    {
        $this->typeWithPhotos();
        $thread = MessageThread::create(['channel' => 'booking.com', 'channex_thread_id' => 'TH-OTA-P', 'guest_name' => 'Ota Guest', 'status' => 'open']);
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Any photos?', 'sent_at' => now()]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                // Deklarimi mungon në OTA…
                if (in_array('send_room_photos', array_column($tools, 'name'), true)) {
                    throw new \RuntimeException('Mjeti i fotove s\'duhej deklaruar në thread OTA.');
                }
                // …dhe edhe executor-i (mbrojtje-në-thellësi) vetë-refuzon.
                $forced = $executors['send_room_photos'](['room_type' => 'Deluxe With Sea View']);
                if (! isset($forced['error'])) {
                    throw new \RuntimeException('Executor-i duhej të refuzonte në thread OTA.');
                }

                return ['args' => ['confident' => false, 'reply' => 'Recepsioni do t\'ju ndihmojë me fotot.', 'kind' => 'informative'], 'toolsUsed' => []];
            });
        });
        $this->mock(\App\Services\ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    public function test_a_type_without_photos_returns_a_clear_error_to_the_model(): void
    {
        RoomType::create(['name' => 'Dhomë Pa Foto', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => []]);
        [$thread, $message] = $this->whatsappThread();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $result = $executors['send_room_photos'](['room_type' => 'Dhomë Pa Foto']);
                if (! isset($result['error']) || ! str_contains($result['error'], 'foto')) {
                    throw new \RuntimeException('Pritej error i qartë për tipologji pa foto.');
                }

                return ['args' => ['confident' => true, 'reply' => 'Për këtë dhomë s\'kemi foto të ngarkuara ende — recepsioni jua dërgon me kënaqësi.', 'kind' => 'informative'], 'toolsUsed' => []];
            });
        });
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('sendImage')->never();
            // Pa asnjë burim besimi (0 foto në quotes, 0 FAQ) → draft, s'dërgohet.
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    public function test_a_drafted_reply_sends_no_photos_at_all(): void
    {
        $this->typeWithPhotos(2);
        [$thread, $message] = $this->whatsappThread();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $executors['send_room_photos'](['room_type' => 'Deluxe With Sea View']);

                // Modeli s'është i sigurt → draft: fotot e mbledhura s'guxojnë të dalin.
                return ['args' => ['confident' => false, 'reply' => 'Duhet ta verifikoj me recepsionin.', 'kind' => 'informative'], 'toolsUsed' => ['send_room_photos']];
            });
        });
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('sendImage')->never();
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertSame(0, Message::query()->where('message_thread_id', $thread->id)->where('sent_by_ai', true)->count());
    }
}
