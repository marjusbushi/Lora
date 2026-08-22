<?php

namespace App\Services;

use App\Contracts\AiChatProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shoferi OpenAI i derës së përbashkët AI (task #408) — piloti gpt-5.6-luna.
 *
 * ÇELËSI QENDROR si te Gemini (#407): PlatformSetting 'ai.openai_key' me env
 * OPENAI_API_KEY si rezervë; asnjë hotel s'konfiguron gjë. Modeli i FIKSUAR
 * në config (kurrë alias — mësimi i stuhive 503 të Gemini-t).
 *
 * KONTRATA E TELIT (mësimet e MISTAKE #145 të zbatuara që në ditën e parë):
 * radha e asistentit kthehet VERBATIM në historik (message-i i plotë i
 * dekoduar — çdo fushë shtesë e modelit mbijeton round-trip-in); çdo
 * tool_call merr përgjigjen e vet me tool_call_id; finalja pranohet vetëm
 * si thirrje e vetme (ose kur raundi e detyron). reasoning_effort=low —
 * llogaritë i bën motori ynë, modeli vetëm flet; edhe kosto, edhe shpejtësi
 * (Luna dokumentohet llafazane — maxTokens e kufizon).
 */
class OpenAiClient implements AiChatProvider
{
    /** Njësoj si te Gemini (task #403): ngadalësia ekstreme = dështim i shpejtë, jo pritje e mysafirit. */
    private const CALL_TIMEOUT_CAP = 30;

    public function key(): ?string
    {
        return \App\Models\PlatformSetting::get('ai.openai_key') ?: config('services.openai.key');
    }

    public function configured(): bool
    {
        return ! empty($this->key());
    }

    public function model(): string
    {
        return (string) config('services.openai.model');
    }

    private function base(): string
    {
        return rtrim((string) config('services.openai.base_url'), '/');
    }

    /**
     * @param  array<int,array{name:string,description?:string,input_schema?:array}>  $tools
     * @param  array<string,callable(array):array>  $executors
     * @return array{args:array<string,mixed>,toolsUsed:array<int,string>,usage:array{input:int,output:int,thinking:int,provider:string,model:string}}
     */
    public function converse(string $system, string $userMessage, array $tools, array $executors, string $finalToolName, int $maxTokens = 2048, int $timeoutSeconds = 60, int $maxToolRounds = 3, ?callable $onUsage = null): array
    {
        $declarations = collect($tools)->map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
            ],
        ])->values()->all();

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMessage],
        ];
        $toolsUsed = [];
        $usage = ['input' => 0, 'output' => 0, 'thinking' => 0];
        $deadline = microtime(true) + $timeoutSeconds;

        for ($round = 0; $round <= $maxToolRounds; $round++) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 3) {
                throw new RuntimeException('Koha e bisedës me modelin u mbush para përgjigjes finale.');
            }

            // Raundi i fundit DETYRON finalen — cikli s'mbetet kurrë pa dalje.
            $forceFinal = $round === $maxToolRounds;
            $turn = $this->complete($messages, $declarations, $forceFinal ? $finalToolName : null, $maxTokens, $deadline, $onUsage);
            foreach ($usage as $k => $v) {
                $usage[$k] = $v + $turn['usage'][$k];
            }

            // Finalja pranohet VETËM si thirrje e vetme e radhës (ose e detyruar)
            // — e njëjta semantikë si porta e radhës së përzier te Gemini
            // (gjetje Codex PR #494): një finale paralel me një mjet do të
            // kthente toolsUsed bosh dhe shifra të paverifikuara.
            if (count($turn['calls']) === 1 || $forceFinal) {
                foreach ($turn['calls'] as $call) {
                    if ($call['name'] === $finalToolName) {
                        return [
                            'args' => $call['args'],
                            'toolsUsed' => $toolsUsed,
                            'usage' => $usage + ['provider' => 'openai', 'model' => $this->model()],
                        ];
                    }
                }
            }

            // Radha e asistentit kthehet VERBATIM (message-i i plotë i dekoduar,
            // me çdo fushë që modeli dërgoi) — i njëjti parim si thoughtSignature
            // te Gemini: historiku i rindërtuar me dorë është minë me sahat.
            $messages[] = $turn['message'];

            foreach ($turn['calls'] as $call) {
                if ($call['name'] === $finalToolName) {
                    $result = ['error' => 'Përfundo së pari raundin e mjeteve; dërgoje '.$finalToolName.' si thirrje të vetme, të bazuar në rezultatet e mjeteve.'];
                } else {
                    $runner = $executors[$call['name']] ?? null;
                    $result = $runner ? $runner($call['args']) : ['error' => 'Mjet i panjohur.'];
                    $toolsUsed[] = $call['name'];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($result ?: new \stdClass, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        throw new RuntimeException("Modeli s'e mbylli përgjigjen brenda raundeve të lejuara.");
    }

    /**
     * Një thirrje chat/completions që DUHET të kthejë tool_calls. Kthen
     * message-in VERBATIM (array i dekoduar i plotë) + thirrjet e nxjerra.
     *
     * @return array{message:array<string,mixed>,calls:array<int,array{id:string,name:string,args:array<string,mixed>}>,usage:array{input:int,output:int,thinking:int}}
     */
    private function complete(array $messages, array $declarations, ?string $forceToolName, int $maxTokens, float $deadline, ?callable $onUsage = null): array
    {
        $slot = fn (): int => max(3, (int) floor(min(self::CALL_TIMEOUT_CAP, $deadline - microtime(true))));

        $payload = [
            'model' => $this->model(),
            'messages' => $messages,
            'tools' => $declarations,
            // 'required' = modeli DUHET të thërrasë NJË mjet (cilindo) — jehona
            // e mode=ANY të Gemini-t; raundi final detyron mjetin e saktë.
            'tool_choice' => $forceToolName
                ? ['type' => 'function', 'function' => ['name' => $forceToolName]]
                : 'required',
            'max_completion_tokens' => $maxTokens,
            // 'none' i DETYRUAR për LUNA-n me mjete (provë reale 2026-08-22;
            // Codex #572 P1 + #573 P2): API-ja refuzon me 400 çdo effort tjetër
            // për gpt-5.6-luna me mjete në chat/completions — edhe një env i
            // vjetër =low do t'i rikthente 400-at. Modelet e TJERA (mbivendosje
            // e ardhshme OPENAI_MODEL) mbajnë vlerën e konfiguruar — dikush
            // prej tyre mund të mos e pranojë fare 'none'.
            'reasoning_effort' => $declarations !== [] && str_starts_with($this->model(), 'gpt-5.6-luna')
                ? 'none'
                : (string) config('services.openai.reasoning_effort', 'none'),
        ];

        // Ngecja trajtohet si dështim kalimtar — "(timeout)" e njeh riprova
        // e ftohtë e failed() njësoj si 5xx (task #403).
        try {
            $res = Http::withToken((string) $this->key())
                ->timeout($slot())
                ->post($this->base().'/chat/completions', $payload);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new RuntimeException('OpenAI nuk u përgjigj në kohë (timeout). Provo sërish.');
        }

        if (! $res->successful()) {
            $this->throwHttpError($res->status(), (string) $res->body());
        }

        // Faturimi per-RAUND, PARA çdo validimi (gjetje Codex #569 P1): një
        // 200 pa tool_calls të vlefshme (finish_reason=length, args të
        // palexueshme) është FATURUAR njësoj — dëshmia regjistrohet edhe kur
        // më poshtë hidhet përjashtim.
        $roundUsage = [
            'input' => (int) $res->json('usage.prompt_tokens', 0),
            // completion_tokens i PËRFSHIN tokenat e arsyetimit — ndahen
            // që të mos faturohen dy herë (gjetje Codex #568 P1): output
            // + thinking = completion_tokens, saktësisht.
            'output' => max(0, (int) $res->json('usage.completion_tokens', 0) - (int) $res->json('usage.completion_tokens_details.reasoning_tokens', 0)),
            'thinking' => (int) $res->json('usage.completion_tokens_details.reasoning_tokens', 0),
        ];
        if ($onUsage !== null) {
            $onUsage($roundUsage + ['provider' => 'openai', 'model' => $this->model()]);
        }

        $message = $res->json('choices.0.message');
        $calls = [];
        foreach ($res->json('choices.0.message.tool_calls', []) ?? [] as $toolCall) {
            $name = $toolCall['function']['name'] ?? null;
            if ($name === null) {
                continue;
            }
            // arguments vjen si STRING JSON — objekt bosh '{}' për mjete pa
            // parametra; JSON i pavlefshëm → dështim i qartë, jo args bosh
            // në heshtje (siguria e portave varet nga args të vërteta).
            $decoded = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Modeli ktheu argumente të palexueshme për mjetin {$name}. Provo sërish.");
            }
            $calls[] = ['id' => (string) ($toolCall['id'] ?? ''), 'name' => $name, 'args' => $decoded];
        }

        if ($calls === [] || ! is_array($message)) {
            $finish = $res->json('choices.0.finish_reason');
            throw new RuntimeException($finish === 'length'
                ? 'Modeli u ndërpre para se ta mbaronte përgjigjen (buxheti i tokenave u mbush). Provo sërish.'
                : "Modeli s'ktheu një thirrje mjeti të vlefshme. Provo sërish.");
        }

        return [
            'message' => $message,
            'calls' => $calls,
            'usage' => $roundUsage,
        ];
    }

    private function throwHttpError(int $status, string $body): never
    {
        // Çelësi udhëton vetëm në header — trupi i gabimit s'e mban dot.
        // "gabim (5xx)" përputhet me regex-in e riprovës së ftohtë (task #403).
        throw new RuntimeException(match (true) {
            $status === 429 => 'Shumë kërkesa te OpenAI (limiti u kalua). Prit pak minuta dhe provo sërish.',
            in_array($status, [401, 403], true) => 'Çelësi qendror OpenAI u refuzua ('.$status.') — njofto mbështetjen e Lora PMS.',
            $status === 404 => 'Modeli OpenAI nuk u gjet (404) — mund të jetë tërhequr. Njofto zhvilluesin.',
            default => "OpenAI ktheu një gabim ($status). Provo sërish.",
        });
    }
}
