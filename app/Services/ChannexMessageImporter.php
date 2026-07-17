<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
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
        if ($expectedPropertyId !== null
            && ($expectedPropertyId === '' || $property === '' || $property !== $expectedPropertyId)) {
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

        return DB::transaction(function () use ($threadId, $messageId, $sender, $body, $hasAttachment, $payload, $expectedPropertyId, $property) {
            $thread = MessageThread::where('channex_thread_id', $threadId)->first();

            if (! $thread) {
                // The thread callback is also an independent tenant-identity
                // check. A matching webhook payload must not be allowed to name
                // a foreign (or unverifiable) thread id.
                $details = null;
                $attr = [];
                try {
                    $details = $this->channex->getMessageThread($threadId);
                    $attr = $details['attributes'] ?? $details ?? [];
                } catch (\Throwable $e) {
                    report($e);
                }

                $expectedThreadProperty = $expectedPropertyId ?? ($property !== '' ? $property : null);
                $threadProperty = (string) ($attr['property_id']
                    ?? data_get($details, 'relationships.property.data.id', ''));
                if ($expectedThreadProperty !== null
                    && ($expectedThreadProperty === ''
                        || $threadProperty === ''
                        || $threadProperty !== $expectedThreadProperty)) {
                    return ['status' => 'foreign_property'];
                }

                $bookingId = $payload['booking_id'] ?? ($attr['booking_id'] ?? null);

                // Best-effort link to the stay: OTA reservations imported from
                // Channex carry the same booking id, so the inbox can show the
                // room, dates and folio next to the conversation.
                $reservationId = $this->resolveReservationId($bookingId ? (string) $bookingId : null);

                $thread = MessageThread::create([
                    'channex_thread_id' => $threadId,
                    'channel' => $this->channelFrom($attr),
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
                    // A guest writing into a closed conversation reopens it —
                    // otherwise the message would hide in the "closed" tab.
                    if ($thread->status === 'closed') {
                        $thread->status = 'open';
                    }
                }
                $thread->save();
            }

            return ['status' => 'ok', 'thread_id' => $thread->id];
        });
    }

    /**
     * Backfill one thread from the Channex API (list endpoint object shape:
     * id + attributes + optional relationships). Imports the thread and ALL
     * its messages with their original timestamps. Historical messages do NOT
     * bump unread_count by default — a first import must not flood reception
     * with badges and sounds for conversations already answered on the OTA.
     *
     * @return array{status:string,thread_id?:int,imported?:int}
     */
    public function importThreadFromApi(array $threadObject, bool $markUnread = false): array
    {
        $attr = $threadObject['attributes'] ?? [];
        $threadId = (string) ($threadObject['id'] ?? $attr['id'] ?? '');
        if ($threadId === '') {
            return ['status' => 'no_thread'];
        }

        // Refuse a thread of another property (= another hotel), same guard
        // as the webhook path. The id may sit in attributes or relationships.
        $property = (string) ($attr['property_id']
            ?? data_get($threadObject, 'relationships.property.data.id', ''));
        $expected = $this->channex->propertyId();
        if ($expected === '' || $property === '' || $property !== $expected) {
            return ['status' => 'foreign_property'];
        }

        $bookingId = $attr['booking_id']
            ?? data_get($threadObject, 'relationships.booking.data.id');

        $apiMessages = $this->channex->getThreadMessages($threadId);

        return DB::transaction(function () use ($threadId, $attr, $bookingId, $apiMessages, $markUnread) {
            // firstOrNew (not firstOrCreate) so a re-run also HEALS an existing
            // thread that was imported before this data existed — a missing
            // channel, guest name, or reservation link fills in without dupes.
            $thread = MessageThread::firstOrNew(['channex_thread_id' => $threadId]);
            $thread->channel = $thread->channel ?: $this->channelFrom($attr);
            $thread->guest_name = $thread->guest_name ?: ($attr['title'] ?? ($attr['guest_name'] ?? null));
            // The thread API carries the state as is_closed; only fill when we
            // have no local status yet — a re-run must not override reception's
            // own close/reopen decisions.
            $thread->status = $thread->status
                ?: ($attr['status'] ?? (($attr['is_closed'] ?? false) ? 'closed' : 'open'));
            $thread->channex_booking_id = $thread->channex_booking_id ?: $bookingId;
            if (! $thread->reservation_id && $thread->channex_booking_id) {
                $thread->reservation_id = $this->resolveReservationId((string) $thread->channex_booking_id);
            }
            $thread->save();

            $imported = 0;
            $latest = null;
            foreach ($apiMessages as $apiMessage) {
                $mAttr = $apiMessage['attributes'] ?? [];
                $messageId = (string) ($apiMessage['id'] ?? $mAttr['id'] ?? '');
                if ($messageId !== '' && Message::where('channex_message_id', $messageId)->exists()) {
                    continue;
                }

                $sender = ($mAttr['sender'] ?? 'guest') === 'guest' ? Message::SENDER_GUEST : Message::SENDER_HOST;
                // Channex timestamps are UTC; convert before saving — the datetime
                // cast stores the wall time verbatim and re-reads it as app-local.
                $sentAt = ! empty($mAttr['inserted_at'])
                    ? Carbon::parse($mAttr['inserted_at'])->setTimezone(config('app.timezone'))
                    : now();

                $message = $thread->messages()->create([
                    'channex_message_id' => $messageId ?: null,
                    'sender' => $sender,
                    'body' => (string) ($mAttr['message'] ?? ''),
                    'has_attachment' => (bool) ($mAttr['have_attachment'] ?? false),
                    'sent_at' => $sentAt,
                ]);

                $imported++;
                if ($latest === null || $message->sent_at->gte($latest->sent_at)) {
                    $latest = $message;
                }
                if ($markUnread && $sender === Message::SENDER_GUEST) {
                    $thread->unread_count++;
                }
            }

            if ($latest !== null
                && ($thread->last_message_at === null || $latest->sent_at->gte($thread->last_message_at))) {
                $thread->last_message_preview = mb_substr($latest->body, 0, 280);
                $thread->last_message_at = $latest->sent_at;
            }
            $thread->save();

            return ['status' => 'ok', 'thread_id' => $thread->id, 'imported' => $imported];
        });
    }

    /**
     * Find the PMS reservation for a Channex booking id. Reservations imported
     * after the messaging release carry channex_booking_id; OLDER ones do not —
     * for those, resolve via the booking's ota_reservation_code (the OTA's own
     * ref, stored on our side as channel_ref) and stamp the id for next time.
     */
    private function resolveReservationId(?string $bookingId): ?int
    {
        if (! $bookingId) {
            return null;
        }

        $id = Reservation::where('channex_booking_id', $bookingId)->value('id');
        if ($id) {
            return $id;
        }

        try {
            $booking = $this->channex->getBooking($bookingId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $ref = (string) data_get($booking, 'attributes.ota_reservation_code', '');
        if ($ref === '') {
            return null;
        }

        $reservation = Reservation::where('channel_ref', $ref)->first();
        if (! $reservation) {
            return null;
        }

        $reservation->forceFill(['channex_booking_id' => $bookingId])->save();

        return $reservation->id;
    }

    /**
     * The platform a thread belongs to. Channex sends it as 'channel' on some
     * payloads but as 'provider' (e.g. "BookingCom") on the thread API —
     * normalize to the channel slugs the inbox badges understand.
     */
    private function channelFrom(array $attr): ?string
    {
        if (! empty($attr['channel'])) {
            return $attr['channel'];
        }

        return match ((string) ($attr['provider'] ?? '')) {
            '' => null,
            'BookingCom' => 'booking.com',
            'AirBNB', 'Airbnb' => 'airbnb',
            'Expedia', 'ExpediaGroup' => 'expedia',
            'Agoda' => 'agoda',
            default => strtolower((string) $attr['provider']),
        };
    }
}
