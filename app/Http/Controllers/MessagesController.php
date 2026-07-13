<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Services\ChannexClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest-messaging inbox (hotel panel). All queries run through the tenant global
 * scope, so a hotel only ever sees its own conversations.
 */
class MessagesController extends Controller
{
    /** Starter templates until the hotel writes its own. */
    private const DEFAULT_QUICK_REPLIES = [
        ['label' => 'Orari i check-in', 'text' => 'Check-in-i standard është ora 14:00 dhe check-out-i ora 11:00. Nëse ju nevojitet ndryshe, na thoni dhe do të mundohemi t\'ju akomodojmë.'],
        ['label' => 'Parking & aksesi', 'text' => 'Kemi parking privat falas brenda oborrit. Adresa e saktë dhe udhëzimet do t\'jua dërgojmë një ditë para mbërritjes.'],
        ['label' => 'Orari i mëngjesit', 'text' => 'Mëngjesi shërbehet çdo ditë nga ora 08:00 deri në 10:30 në restorantin tonë me pamje nga deti.'],
        ['label' => 'Faleminderit!', 'text' => 'Faleminderit shumë! Presim t\'ju mirëpresim. Mirë se vini në Villa Mucho.'],
    ];

    public function index(Request $request): Response
    {
        $threads = MessageThread::query()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $selectedId = $request->integer('thread') ?: $threads->first()?->id;
        $selected = null;

        if ($selectedId) {
            $thread = MessageThread::with(['messages', 'reservation.room.roomType', 'reservation.guest'])->find($selectedId);
            if ($thread) {
                if ($thread->unread_count > 0) {
                    $thread->forceFill(['unread_count' => 0])->save();
                }
                $r = $thread->reservation;
                $selected = [
                    'id' => $thread->id,
                    'guest_name' => $thread->guest_name,
                    'guest_email' => $r?->guest?->email,
                    'channel' => $thread->channel,
                    'status' => $thread->status,
                    'can_reply' => (bool) $thread->channex_thread_id,
                    'reservation' => $r ? [
                        'id' => $r->id,
                        'ref' => $r->channel_ref,
                        'status' => $r->status,
                        'room' => trim(($r->room?->room_number ? 'Dhoma '.$r->room->room_number : '')
                            .($r->room?->roomType?->name ? ' · '.$r->room->roomType->name : '')) ?: null,
                        'check_in' => $r->check_in_date?->toDateString(),
                        'check_out' => $r->check_out_date?->toDateString(),
                        'nights' => $r->check_in_date && $r->check_out_date
                            ? $r->check_in_date->diffInDays($r->check_out_date) : null,
                        'adults' => $r->adults,
                        'total' => (float) $r->total_amount,
                    ] : null,
                    'messages' => $thread->messages->map(fn (Message $m) => [
                        'id' => $m->id,
                        'sender' => $m->sender,
                        'body' => $m->body,
                        'sent_at' => $m->sent_at?->toIso8601String(),
                    ]),
                ];
            }
        }

        return Inertia::render('Messages/Index', [
            'threads' => $threads->map(fn (MessageThread $t) => [
                'id' => $t->id,
                'guest_name' => $t->guest_name ?: 'Mysafir',
                'channel' => $t->channel,
                'preview' => $t->last_message_preview,
                'last_message_at' => $t->last_message_at?->toIso8601String(),
                'unread' => $t->unread_count,
                'status' => $t->status,
            ]),
            'selected' => $selected,
            'quickReplies' => $this->quickReplies(),
        ]);
    }

    /** Close a finished conversation — it moves to the "Të mbyllura" tab (Channex-synced). */
    public function close(MessageThread $thread, ChannexClient $channex): RedirectResponse
    {
        if ($thread->channex_thread_id) {
            try {
                $channex->closeMessageThread($thread->channex_thread_id);
            } catch (\Throwable $e) {
                report($e);

                return back()->with('error', 'Nuk u mbyll dot në Channex. Provo sërish.');
            }
        }

        $thread->forceFill(['status' => 'closed'])->save();

        return back()->with('success', 'Biseda u mbyll.');
    }

    /** Reopen a closed conversation (Channex-synced). */
    public function reopen(MessageThread $thread, ChannexClient $channex): RedirectResponse
    {
        if ($thread->channex_thread_id) {
            try {
                $channex->openMessageThread($thread->channex_thread_id);
            } catch (\Throwable $e) {
                report($e);

                return back()->with('error', 'Nuk u rihap dot në Channex. Provo sërish.');
            }
        }

        $thread->forceFill(['status' => 'open'])->save();

        return back()->with('success', 'Biseda u rihap.');
    }

    /** Save the hotel's own quick-reply templates (per-tenant Setting). */
    public function saveQuickReplies(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'replies' => ['present', 'array', 'max:20'],
            'replies.*.label' => ['required', 'string', 'max:40'],
            'replies.*.text' => ['required', 'string', 'max:1000'],
        ]);

        Setting::set('messages.quick_replies', array_values($data['replies']), 'json');

        return back()->with('success', 'Përgjigjet e shpejta u ruajtën.');
    }

    private function quickReplies(): array
    {
        $stored = Setting::get('messages.quick_replies');

        return is_array($stored) && $stored !== []
            ? array_values($stored)
            : self::DEFAULT_QUICK_REPLIES;
    }

    /** Lightweight unread total for the layout poll (sound + tab badge). */
    public function unread(): JsonResponse
    {
        return response()->json(['count' => (int) MessageThread::sum('unread_count')]);
    }

    public function reply(Request $request, MessageThread $thread, ChannexClient $channex): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        if (! $thread->channex_thread_id) {
            return back()->with('error', 'Kjo bisedë s\'ka lidhje aktive me Channex.');
        }

        try {
            $channex->sendThreadMessage($thread->channex_thread_id, $data['body']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Nuk u dërgua dot mesazhi. Provo sërish.');
        }

        // Mirror the sent reply locally so it shows immediately (Channex may also
        // echo it back via webhook — deduped on channex_message_id, null here).
        $thread->messages()->create([
            'channex_message_id' => null,
            'sender' => Message::SENDER_HOST,
            'body' => $data['body'],
            'sent_at' => now(),
        ]);
        $thread->forceFill([
            'last_message_preview' => mb_substr($data['body'], 0, 280),
            'last_message_at' => now(),
        ])->save();

        return back()->with('success', 'Mesazhi u dërgua.');
    }
}
