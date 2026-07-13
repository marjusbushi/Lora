<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Support\Facades\DB;

/**
 * Ingests an inbound Channex 'message' webhook into per-tenant threads/messages.
 * Runs INSIDE the tenant context set by ResolveTenant (host -> tenant). Refuses
 * a message for another property (= another hotel) so a misdelivered webhook can
 * never write into the wrong tenant's inbox. Idempotent on channex_message_id.
 */
class ChannexMessageImporter
{
    public function __construct(private readonly ChannexClient $channex) {}

    /**
     * @param  array  $payload  the webhook 'payload' object
     * @return array{status:string,thread_id?:int}
     */
    public function importMessage(array $payload, ?string $expectedPropertyId = null): array
    {
        $property = (string) ($payload['property_id'] ?? '');
        if ($expectedPropertyId !== null && $expectedPropertyId !== ''
            && $property !== '' && $property !== $expectedPropertyId) {
            return ['status' => 'foreign_property'];
        }

        $threadId = (string) ($payload['message_thread_id'] ?? '');
        if ($threadId === '') {
            return ['status' => 'no_thread'];
        }

        $messageId = (string) ($payload['id'] ?? '');
        $sender = ($payload['sender'] ?? 'guest') === 'guest' ? Message::SENDER_GUEST : Message::SENDER_HOST;
        $body = (string) ($payload['message'] ?? '');
        $hasAttachment = (bool) ($payload['have_attachment'] ?? false);

        return DB::transaction(function () use ($threadId, $messageId, $sender, $body, $hasAttachment, $payload) {
            $thread = MessageThread::where('channex_thread_id', $threadId)->first();

            if (! $thread) {
                // Enrich guest name + channel from the thread object (best-effort;
                // a failure must not drop the message).
                $attr = [];
                try {
                    $details = $this->channex->getMessageThread($threadId);
                    $attr = $details['attributes'] ?? $details ?? [];
                } catch (\Throwable $e) {
                    report($e);
                }

                $bookingId = $payload['booking_id'] ?? ($attr['booking_id'] ?? null);

                // Best-effort link to the stay: OTA reservations imported from
                // Channex carry the same booking id, so the inbox can show the
                // room, dates and folio next to the conversation.
                $reservationId = $bookingId
                    ? \App\Models\Reservation::where('channex_booking_id', $bookingId)->value('id')
                    : null;

                $thread = MessageThread::create([
                    'channex_thread_id' => $threadId,
                    'channel' => $attr['channel'] ?? null,
                    'channex_booking_id' => $bookingId,
                    'reservation_id' => $reservationId,
                    'guest_name' => $attr['title'] ?? ($attr['guest_name'] ?? null),
                    'status' => $attr['status'] ?? 'open',
                ]);
            }

            $duplicate = $messageId !== '' && Message::where('channex_message_id', $messageId)->exists();
            if (! $duplicate) {
                $thread->messages()->create([
                    'channex_message_id' => $messageId ?: null,
                    'sender' => $sender,
                    'body' => $body,
                    'has_attachment' => $hasAttachment,
                    'sent_at' => now(),
                ]);

                $thread->last_message_preview = mb_substr($body, 0, 280);
                $thread->last_message_at = now();
                if ($sender === Message::SENDER_GUEST) {
                    $thread->unread_count++;
                }
                $thread->save();
            }

            return ['status' => 'ok', 'thread_id' => $thread->id];
        });
    }
}
