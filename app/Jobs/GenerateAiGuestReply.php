<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use App\Models\AuditLog;
use App\Models\HotelFaq;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Services\ChannexClient;
use App\Services\GeminiClient;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Lora AI Chat (propozimi MHQ #22): pas çdo mesazhi të ri të mysafirit, AI-ja
 * përgatit një përgjigje NGA NJOHURITË E HOTELIT (Settings + FAQ). Kur është
 * e sigurt DHE hoteli ka FAQ aktive DHE auto-përgjigja është ndezur → dërgon
 * vetë (etiketuar '· Lora AI'); ndryshe lë DRAFT për stafin. Çmime dhe
 * disponibilitet nuk premtohen KURRË nga AI — ato i takojnë sistemit.
 */
class GenerateAiGuestReply implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 90;

    private const MAX_AI_REPLIES_PER_THREAD_PER_HOUR = 5;

    public function __construct(
        public int $threadId,
        public int $messageId,
    ) {
        $this->captureTenant();
    }

    public function handle(GeminiClient $gemini, ChannexClient $channex, TenantBillingService $billing, TenantContext $context): void
    {
        $tenant = $context->tenant();
        if (! $tenant || ! $billing->enabled(TenantBillingService::MESSAGES, $tenant)) {
            return;
        }

        if (! filter_var(Setting::get('ai_mcp.guest_reply_enabled', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        if (! $gemini->configured()) {
            return;
        }

        $thread = MessageThread::query()->find($this->threadId);
        if (! $thread || $thread->status === 'closed') {
            return;
        }

        // Nëse pas mesazhit të mysafirit ka folur tashmë stafi (ose AI), s'ka
        // vend për përgjigje të dytë — njeriu e ka marrë bisedën në dorë.
        // reorder() heq renditjen ASC të ngulitur në relacion — pa të, latest() s'fiton.
        $latest = $thread->messages()->reorder()->latest('sent_at')->latest('id')->first();
        if (! $latest || $latest->id !== $this->messageId || $latest->sender !== Message::SENDER_GUEST) {
            return;
        }

        // Kufi për thread — çelësi mban tenant_id (rregulli i çelësave per-tenant).
        $rateKey = sprintf('ai-guest-reply:%d:%d', $tenant->id, $thread->id);
        if (Cache::get($rateKey, 0) >= self::MAX_AI_REPLIES_PER_THREAD_PER_HOUR) {
            return;
        }

        $faqs = HotelFaq::query()->active()->ordered()->get(['question', 'answer']);

        $result = $this->askGemini($gemini, $thread, $faqs);
        if ($result === null) {
            return;
        }

        $confident = (bool) ($result['confident'] ?? false);
        $reply = trim((string) ($result['reply'] ?? ''));
        if ($reply === '') {
            return;
        }

        // Thirrja e Gemini-t mund të zgjasë deri në 45s — nëse ndërkohë foli
        // stafi (ose erdhi mesazh i ri), përgjigja jonë është e vjetruar: as
        // dërgim, as draft (gjetje Codex, PR #433).
        $thread->refresh();
        $latest = $thread->messages()->reorder()->latest('sent_at')->latest('id')->first();
        if (! $latest || $latest->id !== $this->messageId || $thread->status === 'closed') {
            return;
        }

        $autoEnabled = filter_var(Setting::get('ai_mcp.guest_auto_reply_enabled', true), FILTER_VALIDATE_BOOL);

        // Niveli 2 VETËM me FAQ aktive — hotel pa FAQ s'e lëshon kurrë AI-në
        // te klientët (de-facto Niveli 1, vendim i ratifikuar i Marjusit).
        if ($confident && $autoEnabled && $faqs->isNotEmpty()) {
            $this->sendAutoReply($channex, $thread, $reply, $rateKey);

            return;
        }

        $thread->forceFill([
            'ai_suggestion' => mb_substr($reply, 0, 2000),
            'ai_suggested_at' => now(),
        ])->save();
    }

    /** @return array<string,mixed>|null */
    private function askGemini(GeminiClient $gemini, MessageThread $thread, $faqs): ?array
    {
        $hotel = collect(Setting::getGroup('hotel'))
            ->filter(fn ($value, $key) => is_scalar($value)
                && $value !== ''
                && ! preg_match('/key|secret|token|password/i', $key))
            ->map(fn ($value, $key) => "- {$key}: {$value}")
            ->implode("\n");

        $faqBlock = $faqs->isEmpty()
            ? '(hoteli s\'ka shtuar ende FAQ)'
            : $faqs->map(fn ($faq) => "P: {$faq->question}\nPËRGJIGJE: {$faq->answer}")->implode("\n\n");

        $conversation = $thread->messages()
            ->reorder()->latest('sent_at')->latest('id')->limit(10)->get()
            ->reverse()
            ->map(fn (Message $message) => ($message->sender === Message::SENDER_GUEST ? 'MYSAFIRI' : 'HOTELI').': '.$message->body)
            ->implode("\n");

        $system = <<<PROMPT
Je recepsionisti virtual i hotelit. Përgjigju mesazhit të fundit të mysafirit
SHKURT, ngrohtë dhe VETËM në gjuhën në të cilën shkroi mysafiri.

RREGULLA TË PATHYESHME:
1. Përgjigju VETËM nga "TË DHËNAT E HOTELIT" dhe "FAQ" më poshtë. Mos shpik asgjë.
2. Nëse pyetja kërkon çmime, disponibilitet, rezervim të ri, ndryshim rezervimi,
   rimbursim, ose diçka që s'gjendet në të dhënat e dhëna → confident=false dhe
   shkruaj një përgjigje të shkurtër ku i thua mysafirit se recepsioni do t'i
   përgjigjet shumë shpejt.
3. Kurrë mos jep linke, çmime apo premtime.
4. confident=true VETËM kur përgjigja mbulohet qartë nga FAQ ose të dhënat.

TË DHËNAT E HOTELIT:
{$hotel}

FAQ:
{$faqBlock}
PROMPT;

        $tool = [
            'name' => 'guest_reply',
            'description' => 'Përgjigja e strukturuar për mysafirin.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'confident' => [
                        'type' => 'boolean',
                        'description' => 'true vetëm kur përgjigja mbulohet plotësisht nga njohuritë e dhëna.',
                    ],
                    'reply' => [
                        'type' => 'string',
                        'description' => 'Teksti i përgjigjes për mysafirin, në gjuhën e tij.',
                    ],
                ],
                'required' => ['confident', 'reply'],
            ],
        ];

        try {
            return $gemini->structured($system, "BISEDA:\n{$conversation}", $tool, 'guest_reply', 1024, 45);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function sendAutoReply(ChannexClient $channex, MessageThread $thread, string $reply, string $rateKey): void
    {
        if (! $thread->channex_thread_id) {
            return;
        }

        try {
            $sent = $channex->sendThreadMessage($thread->channex_thread_id, $reply);
        } catch (\Throwable $e) {
            report($e);

            // Dërgimi dështoi — mos e humb punën: lëre si draft për stafin.
            $thread->forceFill(['ai_suggestion' => mb_substr($reply, 0, 2000), 'ai_suggested_at' => now()])->save();

            return;
        }

        $thread->messages()->create([
            // ID-ja e Channex ruhet që echo e webhook-ut të deduplikohet dhe
            // të mos futet kopje e dytë pa etiketë (gjetje Codex, PR #433).
            'channex_message_id' => (string) ($sent['id'] ?? '') ?: null,
            'sender' => Message::SENDER_HOST,
            'sent_by_ai' => true,
            'body' => $reply,
            'sent_at' => now(),
        ]);
        $thread->forceFill([
            'last_message_preview' => mb_substr($reply, 0, 280),
            'last_message_at' => now(),
            'ai_suggestion' => null,
            'ai_suggested_at' => null,
        ])->save();

        Cache::add($rateKey, 0, now()->addHour());
        Cache::increment($rateKey);

        AuditLog::record('message.ai_reply', $thread, [
            'preview' => mb_substr($reply, 0, 160),
        ], 'ai');
    }
}
