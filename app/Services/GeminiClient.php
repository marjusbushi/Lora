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
    public function structured(string $system, string $userMessage, array $tool, string $toolName, int $maxTokens = 8192): array
    {
        $function = [
            'name' => $tool['name'],
            'description' => $tool['description'] ?? '',
            'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
        ];

        $url = $this->base().'/models/'.$this->model().':generateContent?key='.urlencode((string) $this->key());

        $res = Http::withHeaders(['content-type' => 'application/json'])->timeout(60)->post($url, [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
            'tools' => [['function_declarations' => [$function]]],
            'tool_config' => ['function_calling_config' => ['mode' => 'ANY', 'allowed_function_names' => [$toolName]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.4],
        ]);

        if (!$res->successful()) {
            throw new RuntimeException("Gemini API error ({$res->status()}): ".$res->body());
        }

        foreach ($res->json('candidates.0.content.parts', []) as $part) {
            $call = $part['functionCall'] ?? null;
            if ($call && ($call['name'] ?? null) === $toolName) {
                return $call['args'] ?? [];
            }
        }

        throw new RuntimeException('Gemini returned no function call: '.$res->body());
    }
}
