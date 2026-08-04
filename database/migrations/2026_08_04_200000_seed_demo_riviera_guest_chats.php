<?php

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Reservation;
use App\Models\TenantDomain;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * Demo guest chats for Hotel Demo Riviera so the calendar's message icon can
 * be SEEN on staging (SSH access to the staging box is gone, so the deploy's
 * migration step is the only automated way in). Guarded by the demo domain:
 * on any environment without hotel-demo-riviera this is a no-op, including
 * production. Idempotent via firstOrCreate on the DEMO-CAL thread ids.
 */
return new class extends Migration
{
    private const DEMO_DOMAIN = 'hotel-demo-riviera.staging.lorapms.com';

    public function up(): void
    {
        $domain = TenantDomain::query()->where('domain', self::DEMO_DOMAIN)->with('tenant')->first();
        if (! $domain?->tenant) {
            return;
        }

        $context = app(TenantContext::class);
        $context->set($domain->tenant);

        try {
            // Reservations the default calendar window actually shows.
            $reservations = Reservation::query()
                ->whereNotIn('status', ['cancelled'])
                ->whereDate('check_out_date', '>=', now()->startOfWeek())
                ->whereDate('check_in_date', '<=', now()->startOfWeek()->addDays(13))
                ->orderBy('check_in_date')
                ->limit(3)
                ->get();

            $scenarios = [
                ['suffix' => 1, 'channel' => 'booking.com', 'unread' => 3, 'lines' => [
                    ['guest', 'Hello, what time is check in?', 180],
                    ['host', 'Hello! Check in starts at 14:00 - see you soon.', 150],
                    ['guest', 'Great. Is airport transfer available?', 30],
                    ['guest', 'Also, we will be 3 people, not 2.', 20],
                    ['guest', 'And can we get a room with sea view?', 10],
                ]],
                ['suffix' => 2, 'channel' => 'airbnb', 'unread' => 1, 'lines' => [
                    ['guest', 'Hi! Is parking included in the price?', 45],
                ]],
                ['suffix' => 3, 'channel' => 'booking.com', 'unread' => 0, 'lines' => [
                    ['guest', 'Do you have a baby cot available?', 300],
                    ['host', 'Yes, we will prepare one in your room at no extra cost.', 280],
                    ['guest', 'Perfect, thank you!', 270],
                ]],
            ];

            foreach ($reservations as $index => $reservation) {
                $scenario = $scenarios[$index] ?? null;
                if (! $scenario) {
                    break;
                }

                $guestName = trim(($reservation->guest?->first_name ?? 'Mysafir').' '.($reservation->guest?->last_name ?? ''));
                $lastLine = end($scenario['lines']);

                $thread = MessageThread::firstOrCreate(
                    ['channex_thread_id' => 'DEMO-CAL-'.$scenario['suffix']],
                    [
                        'channel' => $scenario['channel'],
                        'reservation_id' => $reservation->id,
                        'guest_name' => $guestName !== '' ? $guestName : 'Mysafir Demo',
                        'status' => 'open',
                        'unread_count' => $scenario['unread'],
                        'last_message_preview' => $lastLine[1],
                        'last_message_at' => now()->subMinutes($lastLine[2]),
                    ],
                );

                if ($thread->wasRecentlyCreated) {
                    foreach ($scenario['lines'] as $lineNo => [$sender, $body, $minutesAgo]) {
                        Message::create([
                            'message_thread_id' => $thread->id,
                            'channex_message_id' => 'DEMO-CAL-'.$scenario['suffix'].'-'.($lineNo + 1),
                            'sender' => $sender,
                            'body' => $body,
                            'has_attachment' => false,
                            'sent_at' => now()->subMinutes($minutesAgo),
                        ]);
                    }
                }
            }
        } finally {
            $context->clear();
        }
    }

    public function down(): void
    {
        $domain = TenantDomain::query()->where('domain', self::DEMO_DOMAIN)->with('tenant')->first();
        if (! $domain?->tenant) {
            return;
        }

        $context = app(TenantContext::class);
        $context->set($domain->tenant);

        try {
            $threads = MessageThread::query()->where('channex_thread_id', 'like', 'DEMO-CAL-%')->get();
            foreach ($threads as $thread) {
                $thread->messages()->delete();
                $thread->delete();
            }
        } finally {
            $context->clear();
        }
    }
};
