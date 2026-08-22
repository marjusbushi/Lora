<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\MessageThread;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\PricingCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hapi 3 i Lora recepsionistes (task #365): mysafiri zgjodhi ofertën në bisedë
 * dhe Lora krijon MBAJTJEN — rezervim PENDING me të njëjtin transaksion
 * lockForUpdate + re-check si WebsiteController::submitBooking (asnjë
 * double-book), porosi POK dhe link pagese. KONFIRMIMIN e bën vetëm pagesa
 * (PokPayments::settle) — kurrë AI-ja.
 *
 * Guards fail-closed, TË GJITHA server-side (asnjëra s'i besohet promptit):
 * çelësi ai_mcp.whatsapp_booking_enabled default FIKUR; VETËM bisedat
 * whatsapp (një link pagese në thread OTA shkel kushtet e OTA-së); POK i
 * konfiguruar; datat/personat/tipologjia të validuara kundër motorit real.
 */
class AiConversationBooking
{
    /** Kanalet ku Lora LEJOHET të krijojë mbajtje — kurrë OTA. */
    private const ALLOWED_CHANNELS = ['whatsapp'];

    private const MAX_NIGHTS = 31;

    public function __construct(
        private DirectBookingPricing $pricing,
        private PokClient $pok,
    ) {}

    /**
     * Çelësi i veçantë i Hapit 3 — default FIKUR derisa ta ndezë hoteli.
     * Kërkon EDHE auto-përgjigjet WhatsApp (gjetje Codex, PR #504): pa to,
     * mbajtja do krijohej po linku i pagesës do mbetej draft — mysafiri pa
     * link dhe dhoma e bllokuar. UI-ja e tregon të njëjtin varësi.
     */
    public function enabled(): bool
    {
        return filter_var(Setting::get('ai_mcp.whatsapp_booking_enabled', false), FILTER_VALIDATE_BOOL)
            && filter_var(Setting::get('ai_mcp.whatsapp_auto_reply_enabled', false), FILTER_VALIDATE_BOOL);
    }

    /** A deklarohet fare mjeti i rezervimit për këtë bisedë. */
    public function availableFor(MessageThread $thread): bool
    {
        return $this->enabled()
            && in_array((string) $thread->channel, self::ALLOWED_CHANNELS, true)
            && $this->pok->configured();
    }

    /**
     * Krijon mbajtjen. Kthen payload për modelin: ose të dhënat e mbajtjes me
     * linkun e pagesës, ose ['error' => ...] me arsyen që Lora t'ia shpjegojë
     * mysafirit. Nuk hedh përjashtime drejt modelit — dështimet teknike bëhen
     * error i qartë dhe raportohen.
     *
     * @param  array<string,mixed>  $args  check_in, check_out, adults, room_type, guest_first_name, guest_last_name
     * @return array<string,mixed>
     */
    public function hold(MessageThread $thread, array $args): array
    {
        // Ri-verifikim i PLOTË në ekzekutim — mjeti mund të jetë deklaruar në
        // një kërkesë të vjetër ose argumentet të vijnë nga injektim në bisedë.
        if (! $this->enabled()) {
            return ['error' => 'Rezervimi nga biseda është i fikur — drejtoje mysafirin te recepsioni.'];
        }
        if (! in_array((string) $thread->channel, self::ALLOWED_CHANNELS, true)) {
            return ['error' => 'Rezervimi nga kjo bisedë nuk lejohet — drejtoje mysafirin te faqja jonë ose recepsioni.'];
        }
        if (! $this->pok->configured()) {
            return ['error' => 'Pagesa me kartë s\'është e konfiguruar — recepsioni do ta ndjekë rezervimin.'];
        }

        try {
            $checkIn = Carbon::parse((string) ($args['check_in'] ?? ''))->startOfDay();
            $checkOut = Carbon::parse((string) ($args['check_out'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            return ['error' => 'Datat e dhëna nuk kuptohen — kërkoji mysafirit datat në formatin vit-muaj-ditë.'];
        }

        if ($checkIn->lt(today())) {
            return ['error' => 'Data e mbërritjes ka kaluar — kërkoji mysafirit data të ardhshme.'];
        }
        $nights = $checkIn->diffInDays($checkOut, false);
        if ($nights < 1 || $nights > self::MAX_NIGHTS) {
            return ['error' => 'Qëndrimi duhet të jetë 1 deri në '.self::MAX_NIGHTS.' netë — sakteso datat me mysafirin.'];
        }

        $adults = max(1, min(10, (int) ($args['adults'] ?? 0)));
        $firstName = trim((string) ($args['guest_first_name'] ?? '')) ?: trim((string) $thread->guest_name);
        $lastName = trim((string) ($args['guest_last_name'] ?? ''));
        // Pariteti me rezervimin manual (GuestStoreRequest: last_name required)
        // — vendim i Marjusit: të dhëna të plota mysafiri, si në recepsion.
        if (mb_strlen($firstName) < 2 || mb_strlen($lastName) < 2) {
            return ['error' => 'Mungon emri i plotë i mysafirit — pyete për emrin DHE mbiemrin para rezervimit.'];
        }

        $type = RoomType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) ($args['room_type'] ?? '')))])->first();
        if (! $type) {
            return ['error' => 'Tipologjia e kërkuar nuk u gjet — ofroji mysafirit vetëm tipologjitë që ktheu kontrolli i disponibilitetit.'];
        }
        if ($adults > (int) $type->max_occupancy) {
            return ['error' => "Kjo tipologji lejon maksimumi {$type->max_occupancy} persona — ofroji një tipologji më të madhe."];
        }

        // IDEMPOTENCË ndër riprovat e job-it (gjetje Codex, PR #504): raundi
        // final i Gemini-t mund të dështojë PASI mbajtja u krijua — riprova
        // ri-ekzekuton gjithë bisedën. Një mbajtje AI ekzistuese e kësaj bisede
        // me TË NJËJTAT të dhëna rikthehet siç është; me të dhëna të ndryshuara
        // (mysafiri ndërroi mendje) lirohet para se të krijohet e reja.
        $existing = $thread->reservation_id
            ? Reservation::query()->with('room')->find($thread->reservation_id)
            : null;
        if ($existing
            && $existing->status === 'pending'
            && $existing->created_via === Reservation::CREATED_VIA_AI
            && $existing->pok_order_id) {
            $same = $existing->check_in_date?->toDateString() === $checkIn->toDateString()
                && $existing->check_out_date?->toDateString() === $checkOut->toDateString()
                && (int) $existing->adults === $adults
                && (int) $existing->room?->room_type_id === (int) $type->id;

            if ($same) {
                return $this->holdPayload($existing, $type->name, $firstName, $lastName, $nights, $adults);
            }

            // Para anulimit, pajtohu AUTORITATIVISHT me POK (gjetje Codex, PR
            // #505): forma e vjetër mund të jetë ende e hapur — një kapje e
            // kryer do linte mysafirin të paguar për rezervim të anuluar.
            // E paguar → settle e konfirmon dhe ndryshimi refuzohet; POK i
            // paarritshëm → mos prek asgjë (fail-closed).
            try {
                if (app(PokPayments::class)->settle($existing)) {
                    return ['error' => 'Mysafiri e ka PAGUAR tashmë rezervimin e mëparshëm të kësaj bisede — ai është konfirmuar; ndryshimet i bën vetëm recepsioni.'];
                }
            } catch (\Throwable $e) {
                report($e);

                return ['error' => 'Nuk u verifikua dot pagesa e mbajtjes ekzistuese — mos krijo mbajtje të re; recepsioni do ta ndjekë.'];
            }

            $released = Reservation::whereKey($existing->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            if ($released > 0) {
                // UPDATE-i bulk e kapërcen observer-in — kalendarët e hapur
                // njoftohen me dorë (i njëjti model si pok:release-unpaid).
                try {
                    event(new \App\Events\ReservationChanged((int) $existing->tenant_id, (int) $existing->id));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $creator = User::systemForCurrentTenant();

        try {
            $reservation = DB::transaction(function () use ($thread, $type, $checkIn, $checkOut, $adults, $firstName, $lastName, $creator) {
                // Dhomat e tipologjisë provohen NJË NGA NJË: bllokohet rreshti,
                // ri-verifikohet disponibiliteti BRENDA transaksionit — dy
                // kërkesa konkurrente s'e marrin dot kurrë të njëjtën dhomë
                // (i njëjti model si WebsiteController::submitBooking).
                $rooms = Room::query()
                    ->where('room_type_id', $type->id)
                    ->where('status', '!=', 'maintenance')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($rooms as $roomId) {
                    Room::whereKey($roomId)->lockForUpdate()->first();

                    if (! Reservation::isRoomAvailable($roomId, $checkIn->toDateString(), $checkOut->toDateString())) {
                        continue;
                    }

                    $guest = $this->resolveGuest($thread, $firstName, $lastName);
                    $quote = $this->pricing->quote($type, $checkIn->toDateString(), $checkOut->toDateString());

                    return Reservation::create([
                        'room_id' => $roomId,
                        'guest_id' => $guest->id,
                        'check_in_date' => $checkIn->toDateString(),
                        'check_out_date' => $checkOut->toDateString(),
                        'status' => 'pending',
                        'total_amount' => $quote['total'],
                        'rate_before_discount' => $quote['original_total'],
                        'direct_discount_pct' => $quote['discount_pct'],
                        'direct_discount_amount' => $quote['discount_amount'],
                        'adults' => $adults,
                        'notes' => "Krijuar nga Lora në bisedën WhatsApp (thread #{$thread->id}).",
                        'channel' => 'direct',
                        'created_via' => Reservation::CREATED_VIA_AI,
                        'created_by' => $creator->id,
                    ]);
                }

                return null;
            });
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'Sistemi i rezervimeve nuk u përgjigj — mos premto asgjë, recepsioni do ta ndjekë.'];
        }

        if (! $reservation) {
            return ['error' => 'Asnjë dhomë e kësaj tipologjie nuk është më e lirë për këto data — ofroji tipologji tjetër ose data të tjera.'];
        }

        // Porosia POK — pa link pagese mbajtja s'ka vlerë: lirohet menjëherë.
        try {
            $order = $this->pok->createOrder((float) $reservation->total_amount, PricingCurrency::code(), [
                'webhook' => route('website.pay.webhook'),
                'redirect' => route('website.pay.show', $reservation->confirmation_token),
                'fail' => route('website.pay.show', $reservation->confirmation_token),
                'expires' => 30,
            ]);
            $reservation->update(['pok_order_id' => $order['id']]);
        } catch (\Throwable $e) {
            report($e);
            $reservation->update(['status' => 'cancelled']);

            return ['error' => 'Pagesa me kartë nuk u lidh dot tani — recepsioni do ta ndjekë rezervimin.'];
        }

        // Biseda lidhet me rezervimin: get_thread_reservation e sheh menjëherë
        // dhe konfirmimi pas pagesës e gjen thread-in ku duhet të flasë.
        $thread->forceFill(['reservation_id' => $reservation->id])->save();

        AuditLog::record('message.ai_booking_hold', $reservation, [
            'thread_id' => $thread->id,
            'room_type' => $type->name,
            'nights' => $nights,
            'total' => (float) $reservation->total_amount,
            'currency' => PricingCurrency::code(),
        ], 'ai');

        return $this->holdPayload($reservation, $type->name, $firstName, $lastName, $nights, $adults);
    }

    /** @return array<string,mixed> */
    private function holdPayload(Reservation $reservation, string $typeName, string $firstName, string $lastName, int $nights, int $adults): array
    {
        return [
            'status' => 'hold_created',
            'guest_first_name' => $firstName,
            'guest_last_name' => $lastName,
            'room_type' => $typeName,
            'check_in' => $reservation->check_in_date?->toDateString(),
            'check_out' => $reservation->check_out_date?->toDateString(),
            'nights' => $nights,
            'adults' => $adults,
            'stay_total' => round((float) $reservation->total_amount, 2),
            'currency' => PricingCurrency::code(),
            'payment_link' => route('website.pay.show', $reservation->confirmation_token),
            'payment_deadline_minutes' => 30,
        ];
    }

    /**
     * Mysafiri gjendet nga numri i bisedës WhatsApp pa mbishkruar ASNJË të
     * dhënë ekzistuese (anti-tamper si submitBooking): saktësisht NJË person
     * me këtë numër → ripërdoret; zero ose i paqartë → krijohet i ri.
     */
    private function resolveGuest(MessageThread $thread, string $firstName, string $lastName): Guest
    {
        $digits = preg_replace('/\D+/', '', strtok((string) $thread->whatsapp_jid, '@') ?: '');
        $phone = $digits !== '' ? '+'.$digits : null;

        if ($digits !== '' && strlen($digits) >= 8) {
            $suffix = substr($digits, -8);
            $matches = Guest::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '+', ''), ' ', ''), '-', '') LIKE ?", ['%'.$suffix])
                ->get()
                ->filter(fn (Guest $g) => str_ends_with(preg_replace('/\D+/', '', (string) $g->phone), $suffix));

            if ($matches->count() === 1) {
                $guest = $matches->first();
                // VETËM mbiemri BOSH plotësohet, dhe vetëm kur emri përputhet
                // — asnjë e dhënë ekzistuese s'mbishkruhet kurrë (anti-tamper).
                if (trim((string) $guest->last_name) === ''
                    && mb_strtolower(trim((string) $guest->first_name)) === mb_strtolower($firstName)) {
                    $guest->update(['last_name' => $lastName]);
                }

                return $guest;
            }
        }

        return Guest::create([
            'first_name' => $firstName,
            'last_name' => $lastName ?: '',
            'phone' => $phone,
        ]);
    }
}
