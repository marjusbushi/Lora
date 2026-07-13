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
            'https://staging.channex.io/api/v1/message_threads/*/close' => Http::response(['data' => ['attributes' => ['is_closed' => true]]], 200),
            'https://staging.channex.io/api/v1/message_threads/*/open' => Http::response(['data' => ['attributes' => ['is_closed' => false]]], 200),
            'https://staging.channex.io/api/v1/message_threads/*/messages' => Http::response(['data' => ['id' => 'MSG-ECHO']], 200),
            'https://staging.channex.io/api/v1/message_threads/*' => Http::response(['data' => ['attributes' => [
                'title' => 'John Guest', 'channel' => 'booking.com', 'status' => 'open',
            ]]], 200),
            'https://staging.channex.io/api/v1/bookings/*' => Http::response(['data' => [
                'id' => 'BK-1', 'attributes' => ['ota_reservation_code' => 'BK-REF'],
            ]], 200),
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

    private function fakeChannexBackfill(): void
    {
        Http::fake([
            // Most specific first: the messages of a thread…
            'https://staging.channex.io/api/v1/message_threads/*/messages*' => Http::response(['data' => [
                ['id' => 'M-OLD-1', 'attributes' => ['message' => 'Përshëndetje, a keni parking?', 'sender' => 'guest', 'have_attachment' => false, 'inserted_at' => '2026-07-01T10:00:00Z']],
                ['id' => 'M-OLD-2', 'attributes' => ['message' => 'Po, falas për mysafirët.', 'sender' => 'host', 'inserted_at' => '2026-07-01T11:00:00Z']],
            ]], 200),
            'https://staging.channex.io/api/v1/bookings/*' => Http::response(['data' => [
                'id' => 'BK-9', 'attributes' => ['ota_reservation_code' => '6987427272'],
            ]], 200),
            // …then the thread list (one of ours + one of another property).
            // The real thread API carries the platform as 'provider', not 'channel'.
            'https://staging.channex.io/api/v1/message_threads*' => Http::response(['data' => [
                ['id' => 'TH-OLD', 'attributes' => ['title' => 'Maria Guest', 'provider' => 'BookingCom', 'status' => 'open', 'property_id' => 'PROP-1', 'booking_id' => 'BK-9']],
                ['id' => 'TH-FOREIGN', 'attributes' => ['title' => 'Tjetërkush', 'property_id' => 'PROP-OTHER']],
            ]], 200),
        ]);
    }

    public function test_pull_messages_backfills_existing_threads_as_read(): void
    {
        $this->fakeChannexBackfill();

        $this->artisan('channex:pull-messages')->assertExitCode(0);

        // Only OUR property's thread came in; the foreign one was refused.
        $thread = MessageThread::query()->sole();
        $this->assertSame('TH-OLD', $thread->channex_thread_id);
        $this->assertSame('Maria Guest', $thread->guest_name);
        $this->assertSame('booking.com', $thread->channel);
        $this->assertSame(2, $thread->messages()->count());

        // Historical import: no unread flood, original timestamps kept.
        $this->assertSame(0, $thread->unread_count);
        $this->assertSame('2026-07-01 10:00:00', $thread->messages()->where('sender', 'guest')->sole()->sent_at->utc()->toDateTimeString());
        $this->assertSame('Po, falas për mysafirët.', $thread->last_message_preview);
    }

    public function test_pull_messages_is_idempotent(): void
    {
        $this->fakeChannexBackfill();

        $this->artisan('channex:pull-messages')->assertExitCode(0);
        $this->artisan('channex:pull-messages')->assertExitCode(0);

        $this->assertSame(1, MessageThread::query()->count());
        $this->assertSame(2, Message::query()->count());
    }

    public function test_pull_messages_can_mark_guest_messages_unread(): void
    {
        $this->fakeChannexBackfill();

        $this->artisan('channex:pull-messages', ['--mark-unread' => true])->assertExitCode(0);

        // Only the guest message counts as unread, not the host's own reply.
        $this->assertSame(1, MessageThread::query()->sole()->unread_count);
    }

    public function test_quick_replies_can_be_saved_and_are_returned_on_index(): void
    {
        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($home);
        $context->set($home);
        $admin = User::factory()->create(['current_tenant_id' => $home->id]);
        $admin->assignRole('admin');
        $context->clear();

        $this->actingAs($admin)->withSession(['tenant_id' => $home->id])
            ->post(route('messages.quick-replies'), ['replies' => [
                ['label' => 'Wifi', 'text' => 'Fjalëkalimi i wifi-t ju pret në recepsion.'],
            ]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->withSession(['tenant_id' => $home->id])
            ->get(route('messages.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Messages/Index')
                ->has('quickReplies', 1)
                ->where('quickReplies.0.label', 'Wifi'));
    }

    public function test_quick_replies_validation_blocks_bad_rows(): void
    {
        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($home);
        $context->set($home);
        $admin = User::factory()->create(['current_tenant_id' => $home->id]);
        $admin->assignRole('admin');
        $context->clear();

        $this->actingAs($admin)->withSession(['tenant_id' => $home->id])
            ->from(route('messages.index'))
            ->post(route('messages.quick-replies'), ['replies' => [
                ['label' => str_repeat('x', 41), 'text' => 'ok'],
            ]])
            ->assertSessionHasErrors('replies.0.label');
    }

    public function test_thread_links_to_a_legacy_reservation_via_ota_code(): void
    {
        $this->fakeChannexBackfill();

        // A reservation imported BEFORE the messaging release: it has the OTA's
        // own ref (channel_ref) but no channex_booking_id — like all of prod.
        $context = app(TenantContext::class);
        $home = Tenant::query()->sole();
        $context->set($home);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Lidia', 'last_name' => 'Saucedo', 'email' => 'lidia@example.test']);
        $staff = User::factory()->create(['current_tenant_id' => $home->id]);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $staff->id,
            'check_in_date' => '2026-07-12', 'check_out_date' => '2026-07-13',
            'status' => 'confirmed', 'total_amount' => 90, 'adults' => 2,
            'channel' => 'booking.com', 'channel_ref' => '6987427272',
        ]);
        // The thread already exists too (earlier backfill), unlinked.
        MessageThread::create(['channex_thread_id' => 'TH-OLD', 'channex_booking_id' => 'BK-9']);
        $context->clear();

        $this->artisan('channex:pull-messages')->assertExitCode(0);

        $thread = MessageThread::query()->sole();
        $this->assertSame($reservation->id, $thread->reservation_id);
        // …and the reservation is stamped so the next lookup skips the API.
        $this->assertSame('BK-9', $reservation->fresh()->channex_booking_id);
    }

    public function test_closing_and_reopening_a_conversation_syncs_with_channex(): void
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

        $this->actingAs($admin)->withSession(['tenant_id' => $home->id])
            ->post(route('messages.close', $thread->id))
            ->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message_threads/TH-1/close'));
        $this->assertSame('closed', $thread->fresh()->status);

        $this->actingAs($admin)->withSession(['tenant_id' => $home->id])
            ->post(route('messages.reopen', $thread->id))
            ->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message_threads/TH-1/open'));
        $this->assertSame('open', $thread->fresh()->status);
    }

    public function test_a_guest_message_reopens_a_closed_conversation(): void
    {
        $this->fakeChannex();

        $context = app(TenantContext::class);
        $context->set(Tenant::query()->sole());
        $thread = MessageThread::create(['channex_thread_id' => 'TH-1', 'status' => 'closed']);
        $context->clear();

        $this->postJson('/channex/webhook', ['event' => 'message', 'payload' => $this->messagePayload()],
            ['X-Channex-Webhook-Secret' => 'topsecret'])->assertOk();

        // The conversation is back in the Active tab — the message never hides.
        $this->assertSame('open', $thread->fresh()->status);
        $this->assertSame(1, $thread->fresh()->unread_count);
    }

    public function test_pull_messages_heals_an_existing_thread_missing_its_channel(): void
    {
        $this->fakeChannexBackfill();

        // A thread imported before the provider mapping existed: no channel, no name.
        $context = app(TenantContext::class);
        $context->set(Tenant::query()->sole());
        MessageThread::create(['channex_thread_id' => 'TH-OLD', 'channel' => null, 'guest_name' => null]);
        $context->clear();

        $this->artisan('channex:pull-messages')->assertExitCode(0);

        $thread = MessageThread::query()->sole(); // healed in place — no duplicate
        $this->assertSame('booking.com', $thread->channel);
        $this->assertSame('Maria Guest', $thread->guest_name);
        $this->assertSame(2, $thread->messages()->count());
    }
}
