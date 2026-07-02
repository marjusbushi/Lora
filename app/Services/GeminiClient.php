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
    /** Cap on gemini-2.5-flash "thinking" tokens so the forced function call is never starved. */
    private const THINKING_BUDGET = 512;

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
            'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
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
            // The key lives only in a request header, so reading $res->body() below
            // cannot leak it. Map to a clear Albanian message.
            $status = $res->status();
            $body = (string) $res->body();
            throw new RuntimeException(match (true) {
                $status === 429 => 'Shumë kërkesa te Google (limiti u kalua). Prit pak minuta dhe provo sërish.',
                $status === 400 && str_contains($body, 'API key not valid') => 'Çelësi Gemini nuk është i vlefshëm. Kontrollo çelësin te Settings → Asistenti AI.',
                $status === 403 => 'Çelësi Gemini u refuzua (403). Kontrollo çelësin te Settings → Asistenti AI.',
                $status === 404 => 'Modeli i AI nuk u gjet (404) — mund të jetë tërhequr. Njofto zhvilluesin.',
                default => "Google ktheu një gabim ($status). Provo sërish.",
            });
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
