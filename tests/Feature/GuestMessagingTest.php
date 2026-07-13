<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\RoomType;
use App\Models\Room;
use App\Models\Reservation;
use App\Models\Guest;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuestMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
            'services.channex.webhook_secret' => 'topsecret',
        ]);
    }

    private function fakeChannex(): void
    {
        Http::fake([
            'https://staging.channex.io/api/v1/message_threads/*/messages' => Http::response(['data' => ['id' => 'MSG-ECHO']], 200),
            'https://staging.channex.io/api/v1/message_threads/*' => Http::response(['data' => ['attributes' => [
                'title' => 'John Guest', 'channel' => 'booking.com', 'status' => 'open',
            ]]], 200),
        ]);
    }

    private function messagePayload(array $override = []): array
    {
        return array_merge([
            'id' => 'MSG-1',
            'message' => 'A do dhomë me pamje nga deti?',
            'sender' => 'guest',
            'property_id' => 'PROP-1',
            'booking_id' => 'BK-1',
            'message_thread_id' => 'TH-1',
            'have_attachment' => false,
        ], $override);
    }

    public function test_message_webhook_creates_a_thread_and_message(): void
    {
        $this->fakeChannex();

        $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => $this->messagePayload()],
            ['X-Channex-Webhook-Secret' => 'topsecret'])
            ->assertOk();

        $thread = MessageThread::query()->sole();
        $this->assertSame('John Guest', $thread->guest_name);
        $this->assertSame('booking.com', $thread->channel);
        $this->assertSame(1, $thread->unread_count);
        $this->assertSame('A do dhomë me pamje nga deti?', $thread->messages()->first()->body);
        $this->assertSame('guest', $thread->messages()->first()->sender);
    }

    public function test_message_webhook_is_idempotent_on_message_id(): void
    {
        $this->fakeChannex();

        foreach (range(1, 2) as $_) {
            $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => $this->messagePayload()],
                ['X-Channex-Webhook-Secret' => 'topsecret'])->assertOk();
        }

        $this->assertSame(1, MessageThread::query()->count());
        $this->assertSame(1, Message::query()->count());
    }

    public function test_message_webhook_refuses_a_foreign_property(): void
    {
        // No Channex fake needed — a foreign property is rejected before any callback.
        $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => $this->messagePayload(['property_id' => 'PROP-OTHER'])],
            ['X-Channex-Webhook-Secret' => 'topsecret'])
            ->assertOk();

        $this->assertSame(0, MessageThread::query()->count());
    }

    public function test_inbox_only_shows_the_current_hotels_threads(): void
    {
        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($home);

        // A thread in THIS hotel…
        $context->set($home);
        MessageThread::create(['channex_thread_id' => 'TH-HOME', 'channel' => 'airbnb', 'guest_name' => 'Ana', 'last_message_preview' => 'Përshëndetje']);
        $admin = User::factory()->create(['current_tenant_id' => $home->id]);
        $admin->assignRole('admin');

        // …and a thread in ANOTHER hotel.
        $other = Tenant::factory()->create();
        $context->set($other);
        MessageThread::create(['channex_thread_id' => 'TH-OTHER', 'channel' => 'booking.com', 'guest_name' => 'Foreign']);
        $context->clear();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $home->id])
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Messages/Index')
                ->has('threads', 1)
                ->where('threads.0.guest_name', 'Ana'));
    }

    public function test_reply_sends_to_channex_and_stores_a_host_message(): void
    {
        $this->fakeChannex();

        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($home);

        $context->set($home);
        $thread = MessageThread::create(['channex_thread_id' => 'TH-1', 'channel' => 'booking.com', 'guest_name' => 'Ana']);
        $admin = User::factory()->create(['current_tenant_id' => $home->id]);
        $admin->assignRole('admin');
        $context->clear();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $home->id])
            ->post(route('messages.reply', $thread->id), ['body' => 'Sigurisht, e rezervoj për ju.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/message_threads/TH-1/messages')
            && $request['message']['message'] === 'Sigurisht, e rezervoj për ju.');

        $host = Message::where('sender', 'host')->sole();
        $this->assertSame('Sigurisht, e rezervoj për ju.', $host->body);
    }
    public function test_thread_links_to_the_matching_ota_reservation(): void
    {
        $this->fakeChannex();

        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        $context->set($home);
        $type = RoomType::create(['name' => 'Deluxe', 'base_price' => 110, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '204', 'floor' => 2, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Andi', 'last_name' => 'Krasniqi', 'email' => 'andi@example.test']);
        $staff = User::factory()->create(['current_tenant_id' => $home->id]);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $staff->id,
            'check_in_date' => '2026-07-18', 'check_out_date' => '2026-07-21',
            'status' => 'confirmed', 'total_amount' => 330, 'adults' => 2,
            'channel' => 'booking.com', 'channel_ref' => 'BK-REF', 'channex_booking_id' => 'BK-1',
        ]);
        $context->clear();

        $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => $this->messagePayload()],
            ['X-Channex-Webhook-Secret' => 'topsecret'])->assertOk();

        $this->assertSame($reservation->id, MessageThread::query()->sole()->reservation_id);
    }
    public function test_unread_endpoint_returns_the_hotels_total(): void
    {
        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($home);
        $context->set($home);
        MessageThread::create(['channex_thread_id' => 'T1', 'unread_count' => 2]);
        MessageThread::create(['channex_thread_id' => 'T2', 'unread_count' => 3]);
        $admin = User::factory()->create(['current_tenant_id' => $home->id]);
        $admin->assignRole('admin');
        $context->clear();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $home->id])
            ->getJson(route('messages.unread'))
            ->assertOk()
            ->assertJson(['count' => 5]);
    }
}
