<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Reservation;
use App\Services\WhatsAppBridgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Hapi 3 (task #365, pika e): pagesa POK e konfirmoi rezervimin e krijuar nga
 * biseda — Lora i dërgon mysafirit përmbledhjen e konfirmimit NË TË NJËJTIN
 * thread. Dërgohet nga PokPayments::settle VETËM në flip-in pending→confirmed
 * (idempotent aty), plus kyçje atomike këtu kundër riprovave të vetë job-it.
 */
class SendAiBookingConfirmation implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 60];

    public function __construct(public int $reservationId)
    {
        $this->captureTenant();
    }

    public function handle(WhatsAppBridgeClient $whatsapp): void
    {
        $reservation = Reservation::query()->with(['room.roomType', 'guest'])->find($this->reservationId);
        if (! $reservation || $reservation->status !== 'confirmed') {
            return;
        }

        $thread = MessageThread::query()
            ->where('reservation_id', $reservation->id)
            ->where('channel', 'whatsapp')
            ->whereNotNull('whatsapp_jid')
            ->first();
        if (! $thread) {
            return;
        }

        // Kyçje atomike: riprovat e job-it (ose një settle i dyfishtë ekstrem)
        // s'i dërgojnë kurrë mysafirit dy përmbledhje.
        if (! Cache::add(sprintf('ai-booking-confirmed:%d:%d', $reservation->tenant_id, $reservation->id), 1, now()->addDay())) {
            return;
        }

        try {
            // Edhe përmbledhja brenda try-t: një lexim kalimtar që dështon
            // (kursi i monedhës etj.) pas marrjes së kyçjes do ta linte
            // mysafirin e PAGUAR pa konfirmim përgjithmonë (Codex #585).
            $summary = $this->summary($reservation);
            $sent = $whatsapp->send($thread->tenant_id, $thread->whatsapp_jid, $summary);
        } catch (\Throwable $e) {
            // Ura offline — liro kyçjen që riprova e radhës të dërgojë vërtet.
            Cache::forget(sprintf('ai-booking-confirmed:%d:%d', $reservation->tenant_id, $reservation->id));

            throw $e;
        }

        $thread->messages()->create([
            'whatsapp_message_id' => (string) ($sent['id'] ?? '') ?: null,
            'sender' => Message::SENDER_HOST,
            'sent_by_ai' => true,
            'body' => $summary,
            'sent_at' => now(),
        ]);
        $thread->forceFill([
            'last_message_preview' => mb_substr($summary, 0, 280),
            'last_message_at' => now(),
        ])->save();

        AuditLog::record('message.ai_booking_confirmed', $reservation, [
            'thread_id' => $thread->id,
            'total' => (float) $reservation->total_amount,
        ], 'ai');

        try {
            event(new \App\Events\MessageReceived($thread->tenant_id, $thread->id));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Përmbledhje deterministe dygjuhëshe (sq + en) — pa asnjë thirrje AI:
     * pas pagesës mysafiri meriton konfirmim të menjëhershëm e të pagabueshëm.
     */
    private function summary(Reservation $reservation): string
    {
        $ref = strtoupper(substr((string) $reservation->confirmation_token, 0, 8));
        $guest = trim(($reservation->guest?->first_name ?? '').' '.($reservation->guest?->last_name ?? ''));
        $type = $reservation->room?->roomType?->name ?: 'Dhomë';
        $in = $reservation->check_in_date?->toDateString();
        $out = $reservation->check_out_date?->toDateString();
        $total = number_format((float) $reservation->total_amount, 2, '.', '');
        $currency = strtoupper((string) ($reservation->currency ?: \App\Services\PricingCurrency::code()));

        return "✅ Pagesa u konfirmua — rezervimi juaj është i sigurt!\n"
            ."Referenca: {$ref}\n"
            .($guest !== '' ? "Mysafiri / Guest: {$guest}\n" : '')
            ."{$type} · {$in} → {$out} · {$reservation->adults} persona\n"
            ."Totali i paguar: {$total} {$currency}\n\n"
            ."✅ Payment confirmed — your booking is secured!\n"
            ."Reference: {$ref}\n"
            ."We look forward to welcoming you. / Mirë se vini!";
    }
}
