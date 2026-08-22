<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Gemini client for the AI Pricing Assistant. Uses function calling (forced) so the
 * reply is a validated JSON object. Same structured() interface as AnthropicClient, so the
 * two providers are interchangeable. Key comes from the Settings UI (Setting 'ai.gemini_key')
 * or env (GEMINI_API_KEY / GOOGLE_API_KEY).
 */
class GeminiClient
{
    /** Cap on the flash models' "thinking" tokens so the forced function call is never starved (verified accepted by gemini-flash-latest and the pinned gemini-3.7-flash via the real-API tests). */
    private const THINKING_BUDGET = 512;

    /**
     * Tavan per-THIRRJE brenda converse(): në dritaret e mbingarkesës Google
     * mban kërkesa pezull me minuta (504-t e forumit zyrtar; vonesat 2-min që
     * pa Marjusi live). Më mirë e presim në 30s dhe provojmë modelin rezervë
     * sesa ta lëmë mysafirin duke pritur (task #403).
     */
    private const CALL_TIMEOUT_CAP = 30;

    public function key(): ?string
    {
        return Setting::get('ai.gemini_key') ?: config('services.gemini.key');
    }

    public function configured(): bool
    {
        return !empty($this->key());
    }

    public function model(): string
    {
        return (string) (Setting::get('ai.gemini_model') ?: config('services.gemini.model'));
    }

    private function base(): string
    {
        return rtrim((string) config('services.gemini.base_url'), '/');
    }

    /**
     * Force $toolName via function calling; return the function's args (structured object).
     * Accepts the same tool shape as AnthropicClient: {name, description, input_schema}.
     *
     * @return array<string,mixed>
     */
    public function structured(string $system, string $userMessage, array $tool, string $toolName, int $maxTokens = 8192, int $timeoutSeconds = 60): array
    {
        return $this->structuredWithParts(
            $system,
            [['text' => $userMessage]],
            $tool,
            $toolName,
            $maxTokens,
            $timeoutSeconds,
        );
    }

    /**
     * Force a structured response while sending a private image or PDF inline.
     * The binary data and API key are sent only from the server.
     *
     * @return array<string,mixed>
     */
    public function structuredWithInlineData(
        string $system,
        string $userMessage,
        string $bytes,
        string $mimeType,
        array $tool,
        string $toolName,
        int $maxTokens = 4096,
        int $timeoutSeconds = 90,
    ): array {
        return $this->structuredWithParts(
            $system,
            [
                ['text' => $userMessage],
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($bytes)]],
            ],
            $tool,
            $toolName,
            $maxTokens,
            $timeoutSeconds,
        );
    }

    /**
     * Multi-round function-calling conversation (Lora recepsioniste). The model may
     * call any of the $executors tools — PHP runs them and feeds the result back as
     * a functionResponse — for at most $maxToolRounds rounds; the FINAL answer must
     * arrive via $finalToolName (declared in $tools alongside the executor tools).
     * Every round forces mode=ANY over the declared names, so the model always
     * returns a function call — never free text.
     *
     * @param  array<int,array{name:string,description?:string,input_schema?:array}>  $tools
     * @param  array<string,callable(array):array>  $executors  tool name → server-side runner
     * @return array{args:array<string,mixed>,toolsUsed:array<int,string>}
     */
    public function converse(string $system, string $userMessage, array $tools, array $executors, string $finalToolName, int $maxTokens = 2048, int $timeoutSeconds = 60, int $maxToolRounds = 3): array
    {
        $functions = collect($tools)->map(fn (array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'] ?? '',
            'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
        ])->values()->all();
        $allNames = array_column($functions, 'name');

        $contents = [['role' => 'user', 'parts' => [['text' => $userMessage]]]];
        $toolsUsed = [];

        // Modeli AKTIV i kësaj bisede: nis me primarin; nëse një raund shpëtohet
        // nga rezerva, biseda NGJIT te rezerva deri në fund — ping-pong-u mes
        // modeleve mes raundeve rrezikon 400 mbi thoughtSignature-t e huaja.
        $activeModel = $this->model();

        // $timeoutSeconds është kufiri i GJITHË bisedës, jo i çdo kërkese — me
        // 3 raunde mjetesh nga 45s secili, job-i (timeout 90s) vritej në mes
        // dhe s'linte as draft (gjetje Codex, PR #462).
        $deadline = microtime(true) + $timeoutSeconds;

        for ($round = 0; $round <= $maxToolRounds; $round++) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 3) {
                throw new RuntimeException('Koha e bisedës me modelin u mbush para përgjigjes finale.');
            }

            // Raundi i fundit lejon VETËM përgjigjen finale — cikli s'mbetet kurrë pa dalje.
            $allowed = $round === $maxToolRounds ? [$finalToolName] : $allNames;
            $turn = $this->generate($activeModel, $system, $contents, $functions, $allowed, $maxTokens, $deadline);
            $activeModel = $turn['model'];

            // Finalja pranohet VETËM si thirrje e vetme e radhës (ose kur raundi
            // lejon vetëm finalen). Në një radhë të përzier — guest_reply paralel
            // me një mjet — pranimi i menjëhershëm do të kthente toolsUsed/quotes
            // bosh dhe porta e besimit (me FAQ aktive) mund ta dërgonte një
            // përgjigje me shifra të paverifikuara (gjetje Codex, PR #494).
            if (count($turn['calls']) === 1 || $allowed === [$finalToolName]) {
                foreach ($turn['calls'] as $call) {
                    if ($call['name'] === $finalToolName) {
                        return ['args' => $call['args'], 'toolsUsed' => $toolsUsed];
                    }
                }
            }

            // Kthimi i radhës së modelit VERBATIM (me thoughtSignature + id):
            // gemini-3.7-flash kthen 400 INVALID_ARGUMENT ("missing a
            // thought_signature in functionCall parts") nëse historiku
            // rindërtohet vetëm me name+args — prandaj heshtja e prod-it
            // në çdo mesazh që kërkonte mjet (task #379).
            $contents[] = $turn['content'];

            // ÇDO thirrje e radhës merr functionResponse-in e vet, në të njëjtin
            // rend — API-ja pret përgjigje për të gjitha thirrjet paralele. Një
            // finale e parakohshme brenda radhës së përzier refuzohet me gabim,
            // që modeli ta ri-dërgojë të ankoruar në rezultatet e mjeteve.
            $parts = [];
            foreach ($turn['calls'] as $call) {
                if ($call['name'] === $finalToolName) {
                    $result = ['error' => 'Përfundo së pari raundin e mjeteve; dërgoje '.$finalToolName.' si thirrje të vetme, të bazuar në rezultatet e mjeteve.'];
                } else {
                    $runner = $executors[$call['name']] ?? null;
                    $result = $runner
                        ? $runner($call['args'])
                        : ['error' => 'Mjet i panjohur.'];
                    $toolsUsed[] = $call['name'];
                }

                $response = ['name' => $call['name'], 'response' => $result ?: new \stdClass];
                if (isset($call['id'])) {
                    $response['id'] = $call['id'];
                }
                $parts[] = ['functionResponse' => $response];
            }
            $contents[] = ['role' => 'user', 'parts' => $parts];
        }

        throw new RuntimeException("Modeli s'e mbylli përgjigjen brenda raundeve të lejuara.");
    }

    /**
     * One generateContent round that MUST return at least one function call among
     * $allowedNames. Returns the model's content VERBATIM as decoded objects (so
     * thoughtSignature/id and empty-object args survive the round-trip — the API
     * rejects reconstructed history with 400) plus the extracted calls in order,
     * plus the model that actually served the round (converse sticks to it).
     *
     * @param  float  $deadline  microtime(true) kur mbaron buxheti i GJITHË bisedës —
     *                           edhe primari edhe rezerva rrinë brenda tij (gjetje Codex #550).
     * @return array{content:\stdClass,calls:array<int,array{name:string,args:array<string,mixed>,id?:string}>,model:string}
     */
    private function generate(string $model, string $system, array $contents, array $functions, array $allowedNames, int $maxTokens, float $deadline): array
    {
        // Slot-i i çastit: sa kohë i jepet thirrjes SË RADHËS — kurrë mbi tavanin
        // 30s (dorëzimi i shpejtë) dhe kurrë mbi afatin e mbetur të bisedës.
        $slot = fn (): int => max(3, (int) floor(min(self::CALL_TIMEOUT_CAP, $deadline - microtime(true))));
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'tools' => [['function_declarations' => $functions]],
            'tool_config' => ['function_calling_config' => ['mode' => 'ANY', 'allowed_function_names' => $allowedNames]],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => 0.4,
                // gemini-2.5-flash is a THINKING model: without a cap its internal reasoning
                // tokens are billed against maxOutputTokens and can consume the whole budget,
                // leaving no room for the forced function call (finishReason=MAX_TOKENS, empty
                // parts). A small budget keeps light reasoning while guaranteeing output room.
                'thinkingConfig' => ['thinkingBudget' => self::THINKING_BUDGET],
            ],
        ];

        // Ngecja (timeout/rrjet) trajtohet NJËSOJ si 5xx: në dritaret e
        // mbingarkesës Google herë refuzon (503) e herë var pezull (504) —
        // për mysafirin janë e njëjta heshtje (task #403).
        $servedBy = $model;
        try {
            $res = $this->post($model, $payload, $slot());
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $res = null;
        }

        // Dritaret 503 të mbingarkesës (task #403, tri herë live më 2026-08-21):
        // alias-i flash-latest sapo kishte kaluar te 3.7 i posa-lançuar që
        // ngjishet në orë piku. E njëjta kërkesë provohet MENJËHERË te modeli
        // rezervë "lite" (kapacitet më i lirë, pishinë tjetër) — mysafiri merr
        // përgjigje në sekonda; rezerva dështon → gabimi ORIGJINAL bublon
        // (radha riprovon si zakonisht).
        $fallback = trim((string) config('services.gemini.fallback_model'));
        if (($res === null || $res->serverError()) && $fallback !== '' && $fallback !== $model
            // Rezerva provohet VETËM brenda afatit të mbetur të bisedës (gjetje
            // Codex #550): kur primari e hëngri buxhetin duke u varur, s'i
            // shtojmë mysafirit edhe një pritje — bublon dështimi origjinal
            // dhe shkalla e riprovës së job-it rikthehet me buxhet të freskët.
            && $deadline - microtime(true) >= 3) {
            try {
                $fallbackRes = $this->post($fallback, $payload, $slot());
                if ($fallbackRes->successful()) {
                    $res = $fallbackRes;
                    $servedBy = $fallback;
                }
            } catch (\Illuminate\Http\Client\ConnectionException) {
                // Rezerva ngeci edhe ajo — mbetet dështimi origjinal më poshtë.
            }
        }

        if ($res === null) {
            // Mesazhi mban "(timeout)" me qëllim: failed() e njeh si kalimtar
            // dhe planifikon riprovën e ftohtë 5-min, njësoj si 5xx.
            throw new RuntimeException('Google nuk u përgjigj në kohë (timeout). Provo sërish.');
        }

        if (!$res->successful()) {
            $this->throwHttpError($res->status(), (string) $res->body());
        }

        $calls = [];
        foreach ($res->json('candidates.0.content.parts', []) as $part) {
            $call = $part['functionCall'] ?? null;
            if ($call && in_array($call['name'] ?? null, $allowedNames, true)) {
                $extracted = ['name' => $call['name'], 'args' => (array) ($call['args'] ?? [])];
                if (isset($call['id'])) {
                    $extracted['id'] = (string) $call['id'];
                }
                $calls[] = $extracted;
            }
        }

        if ($calls !== []) {
            // Content-i që i kthehet API-së dekodohet si OBJEKTE (stdClass), jo
            // arrays: një mjet pa parametra vjen me `args: {}` dhe dekodimi në
            // array do ta ri-enkodonte si `[]` — functionCall.args duhet të jetë
            // objekt JSON, ndryshe raundi i jehonës kthen 400 (gjetje Codex).
            $content = json_decode((string) $res->body())->candidates[0]->content;

            return ['content' => $content, 'calls' => $calls, 'model' => $servedBy];
        }

        // No function call came back. The usual cause is the thinking budget eating the
        // output — surface that specifically so it is actionable, not a generic failure.
        $finish = $res->json('candidates.0.finishReason');
        throw new RuntimeException($finish === 'MAX_TOKENS'
            ? 'Modeli u ndërpre para se ta mbaronte planin (buxheti i tokenave u mbush). Provo sërish ose zvogëlo periudhën.'
            : "Modeli s'ktheu një plan të vlefshëm. Provo sërish.");
    }

    /**
     * Kontroll shëndeti pothuaj-falas i çelësit (task #382): GET metadata e
     * modelit — 0 tokena. Kthen ok=true (çelësi punon), ok=false + error
     * (i pavlefshëm/i revokuar/model i hequr — dështim DEFINITIV), ose
     * ok=null (kalimtar: 429/5xx/rrjet — mos e shëno të prishur).
     *
     * @return array{ok: ?bool, error: ?string}
     */
    public function healthCheck(): array
    {
        try {
            $res = Http::withHeaders(['x-goog-api-key' => (string) $this->key()])
                ->timeout(10)
                ->get($this->base().'/models/'.$this->model());
        } catch (\Throwable) {
            return ['ok' => null, 'error' => null];
        }

        if ($res->successful()) {
            return ['ok' => true, 'error' => null];
        }

        return match (true) {
            in_array($res->status(), [400, 401, 403], true) => ['ok' => false, 'error' => 'Çelësi u refuzua nga Google ('.$res->status().') — kontrolloni çelësin te Cilësimet → Asistenti AI.'],
            $res->status() === 404 => ['ok' => false, 'error' => 'Modeli i AI nuk u gjet (404) — njoftoni mbështetjen.'],
            default => ['ok' => null, 'error' => null],
        };
    }

    /**
     * Një thirrje generateContent te modeli i dhënë. Çelësi vetëm në HEADER —
     * kurrë në URL (s'rrjedh dot via logje/exception/report).
     */
    private function post(string $model, array $payload, int $timeoutSeconds): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'content-type' => 'application/json',
            'x-goog-api-key' => (string) $this->key(),
        ])->timeout($timeoutSeconds)->post($this->base().'/models/'.$model.':generateContent', $payload);
    }

    private function throwHttpError(int $status, string $body): never
    {
        // The key lives only in a request header, so reading the body here
        // cannot leak it. Map to a clear Albanian message.
        throw new RuntimeException(match (true) {
            $status === 429 => 'Shumë kërkesa te Google (limiti u kalua). Prit pak minuta dhe provo sërish.',
            $status === 400 && str_contains($body, 'API key not valid') => 'Çelësi Gemini nuk është i vlefshëm. Kontrollo çelësin te Settings → Asistenti AI.',
            $status === 403 => 'Çelësi Gemini u refuzua (403). Kontrollo çelësin te Settings → Asistenti AI.',
            $status === 404 => 'Modeli i AI nuk u gjet (404) — mund të jetë tërhequr. Njofto zhvilluesin.',
            default => "Google ktheu një gabim ($status). Provo sërish.",
        });
    }

    /** @return array<string,mixed> */
    private function structuredWithParts(string $system, array $parts, array $tool, string $toolName, int $maxTokens, int $timeoutSeconds): array
    {
        $function = [
            'name' => $tool['name'],
            'description' => $tool['description'] ?? '',
            'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
        ];

        // The key travels in the x-goog-api-key HEADER — never in the URL, so it
        // can never leak via exception messages, access logs, or report() traces.
        $url = $this->base().'/models/'.$this->model().':generateContent';

        $res = Http::withHeaders([
            'content-type' => 'application/json',
            'x-goog-api-key' => (string) $this->key(),
        ])->timeout($timeoutSeconds)->post($url, [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'tools' => [['function_declarations' => [$function]]],
            'tool_config' => ['function_calling_config' => ['mode' => 'ANY', 'allowed_function_names' => [$toolName]]],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => 0.4,
                // gemini-2.5-flash is a THINKING model: without a cap its internal reasoning
                // tokens are billed against maxOutputTokens and can consume the whole budget,
                // leaving no room for the forced function call (finishReason=MAX_TOKENS, empty
                // parts). A small budget keeps light reasoning while guaranteeing output room.
                'thinkingConfig' => ['thinkingBudget' => self::THINKING_BUDGET],
            ],
        ]);

        if (!$res->successful()) {
            $this->throwHttpError($res->status(), (string) $res->body());
        }

        foreach ($res->json('candidates.0.content.parts', []) as $part) {
            $call = $part['functionCall'] ?? null;
            if ($call && ($call['name'] ?? null) === $toolName) {
                return $call['args'] ?? [];
            }
        }

        // No function call came back. The usual cause is the thinking budget eating the
        // output — surface that specifically so it is actionable, not a generic failure.
        $finish = $res->json('candidates.0.finishReason');
        throw new RuntimeException($finish === 'MAX_TOKENS'
            ? 'Modeli u ndërpre para se ta mbaronte planin (buxheti i tokenave u mbush). Provo sërish ose zvogëlo periudhën.'
            : "Modeli s'ktheu një plan të vlefshëm. Provo sërish.");
    }
}
