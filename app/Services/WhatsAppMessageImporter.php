<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\DB;

/**
 * Fut ngjarjet e urës WhatsApp në inbox-in e hotelit. Thirret VETËM nga
 * endpoint-i i ngjarjeve pasi token-i + tenant-i janë verifikuar (kryqëzim
 * host ↔ payload.tenant_id) — këtu konteksti i tenant-it është vendosur.
 */
class WhatsAppMessageImporter
{
    /** @param array<string,mixed> $payload */
    public function importMessage(array $payload): array
    {
        $jid = trim((string) ($payload['jid'] ?? ''));
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));

        if ($jid === '' || $messageId === '' || $body === '') {
            return ['status' => 'skipped'];
        }

        return DB::transaction(function () use ($payload, $jid, $messageId, $body) {
            $thread = MessageThread::query()->where('whatsapp_jid', $jid)->first();

            if (! $thread) {
                $thread = MessageThread::create([
                    'whatsapp_jid' => $jid,
                    'channel' => 'whatsapp',
                    // Emri i profilit (pushName) ose numri nga jid-i si rezervë.
                    'guest_name' => trim((string) ($payload['name'] ?? '')) ?: '+'.strstr($jid, '@', true),
                    'status' => 'open',
                ]);
            }

            if ($thread->messages()->where('whatsapp_message_id', $messageId)->exists()) {
                return ['status' => 'duplicate', 'thread_id' => $thread->id];
            }

            $sentAt = is_numeric($payload['timestamp'] ?? null)
                ? now()->setTimestamp((int) $payload['timestamp'])
                : now();

            try {
                $message = $thread->messages()->create([
                    'whatsapp_message_id' => $messageId,
                    'sender' => Message::SENDER_GUEST,
                    'body' => mb_substr($body, 0, 4000),
                    'sent_at' => $sentAt,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Dy dorëzime paralele kaluan exists() njëkohësisht — indeksi
                // unik (message_thread_id, whatsapp_message_id) e ndal të dytin.
                return ['status' => 'duplicate', 'thread_id' => $thread->id];
            }

            $thread->unread_count++;
            if ($thread->status === 'closed') {
                $thread->status = 'open';
            }
            $thread->last_message_preview = mb_substr($body, 0, 280);
            $thread->last_message_at = now();
            // Rregulli anti-çift-i-gabuar (si te Channex): mesazh i ri = drafti
            // dhe flamuri "s'e dinte" i mesazhit të kaluar s'vlejnë më.
            $thread->ai_suggestion = null;
            $thread->ai_suggested_at = null;
            $thread->ai_unanswered_question = null;
            $thread->save();

            // Lora AI — si te webhook-u Channex: VETËM ngjarjet e gjalla të urës
            // (whatsapp s'ka fare rrugë historiku). Auto-dërgimi aty varet nga
            // çelësi më vete whatsapp_auto_reply_enabled (default FIKUR).
            \App\Jobs\GenerateAiGuestReply::dispatch($thread->id, $message->id)->afterCommit();

            return ['status' => 'ok', 'thread_id' => $thread->id];
        });
    }

    /** @param array<string,mixed> $payload */
    public function applyStatus(array $payload): void
    {
        $status = in_array($payload['status'] ?? null, [
            WhatsAppConnection::STATUS_CONNECTED,
            WhatsAppConnection::STATUS_PAIRING,
            WhatsAppConnection::STATUS_DISCONNECTED,
        ], true) ? $payload['status'] : WhatsAppConnection::STATUS_DISCONNECTED;

        WhatsAppConnection::updateOrCreate([], [
            'status' => $status,
            'phone_number' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'last_event_at' => now(),
        ]);
    }
}
