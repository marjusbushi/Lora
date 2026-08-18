<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\MessageThread;
use App\Models\Reservation;

/**
 * Rezervimi i LIDHUR me një bisedë mysafiri — për mjetin get_thread_reservation
 * të Lora recepsionistes (task #364). RREGULLI I SIGURISË (vendim i ratifikuar):
 * identiteti vjen VETËM nga lidhja e thread-it — reservation_id i vendosur nga
 * importeri, channex_booking_id, ose përputhja e numrit WhatsApp me telefonin
 * e mysafirit. KURRË kërkim me emër/email/id nga teksti i bisedës: një i panjohur
 * në WhatsApp është anonim dhe s'merr dot të dhënat e askujt tjetër.
 *
 * Ekspozon VETËM ç'i duhet mysafirit për qëndrimin e VET: pa email/telefon,
 * pa dokumente identiteti, pa shënime stafi, pa historik rezervimesh të tjera.
 */
class ThreadReservationContext
{
    /** @return array<string,mixed> payload suksesi OSE ['error' => ...] */
    public function forThread(MessageThread $thread): array
    {
        $reservation = $this->resolve($thread);
        if (! $reservation) {
            return ['error' => "Kjo bisedë s'ka rezervim të lidhur — mos jep asnjë të dhënë personale; drejtoje mysafirin te recepsioni."];
        }

        // Bilanci si te ekrani i rezervimit: totali minus pagesat e pavoiduara.
        $paid = (float) $reservation->payments->where('is_voided', false)->sum('amount');
        $total = (float) $reservation->total_amount;

        return [
            'guest_first_name' => $reservation->guest?->first_name,
            'room_type' => $reservation->room?->roomType?->name,
            'room_number' => $reservation->room?->room_number,
            'check_in' => $reservation->check_in_date?->format('Y-m-d'),
            'check_out' => $reservation->check_out_date?->format('Y-m-d'),
            'nights' => $reservation->nights,
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'status' => $reservation->status,
            'currency' => PricingCurrency::code(),
            'total' => $total,
            'paid' => $paid,
            'balance' => round($total - $paid, 2),
        ];
    }

    private function resolve(MessageThread $thread): ?Reservation
    {
        $with = ['guest', 'room.roomType', 'payments'];

        if ($thread->reservation_id) {
            return Reservation::with($with)->find($thread->reservation_id);
        }

        // Thread OTA i vjetër pa heal — provo numrin e prenotimit Channex.
        if ($thread->channex_booking_id) {
            return Reservation::with($with)
                ->where('channex_booking_id', $thread->channex_booking_id)
                ->latest('id')->first();
        }

        if ($thread->whatsapp_jid) {
            return $this->byWhatsAppPhone($thread->whatsapp_jid, $with);
        }

        return null;
    }

    /**
     * Përputhja WhatsApp: shifrat e JID-it (p.sh. 355691234567@s.whatsapp.net)
     * kundër telefonit të mysafirit, të normalizuar në shifra pa '0'-n prijëse
     * lokale. Pranohet vetëm përputhje prapashtese me të paktën 8 shifra —
     * kurrë përputhje e pjesshme e shkurtër (anti-rrjedhje).
     */
    private function byWhatsAppPhone(string $jid, array $with): ?Reservation
    {
        $jidDigits = preg_replace('/\D/', '', strstr($jid, '@', true) ?: $jid);
        if (strlen($jidDigits) < 8) {
            return null;
        }

        $guestIds = Guest::query()
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'phone'])
            ->filter(function (Guest $guest) use ($jidDigits): bool {
                $digits = ltrim(preg_replace('/\D/', '', (string) $guest->phone), '0');

                return strlen($digits) >= 8
                    && (str_ends_with($jidDigits, $digits) || str_ends_with($digits, $jidDigits));
            })
            ->pluck('id');

        if ($guestIds->isEmpty()) {
            return null;
        }

        $base = Reservation::with($with)
            ->whereIn('guest_id', $guestIds)
            ->where('status', '!=', 'cancelled');
        $today = now()->startOfDay();

        // Rezervimi më i afërt për bisedën: në shtëpi tani → i ardhshmi më i afërt
        // → i fundit i kaluar. GJITHMONË vetëm NJË — pa historik (kufi i task #364).
        return (clone $base)
            ->whereDate('check_in_date', '<=', $today)
            ->whereDate('check_out_date', '>', $today)
            ->latest('id')->first()
            ?? (clone $base)
                ->whereDate('check_in_date', '>=', $today)
                ->orderBy('check_in_date')->orderBy('id')->first()
            ?? (clone $base)
                ->orderByDesc('check_out_date')->orderByDesc('id')->first();
    }
}
