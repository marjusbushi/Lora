<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Anthropic's Messages API. Used by the AI Pricing Assistant. The API key
 * comes from the Settings UI (Setting 'ai.anthropic_key') or env. structured() forces a single
 * tool so Claude's reply is a validated JSON object (no fragile text parsing).
 */
class AnthropicClient
{
    public function key(): ?string
    {
        return Setting::get('ai.anthropic_key') ?: config('services.anthropic.key');
    }

    public function configured(): bool
    {
        return !empty($this->key());
    }

    public function model(): string
    {
        return (string) (Setting::get('ai.anthropic_model') ?: config('services.anthropic.model'));
    }

    private function base(): string
    {
        return rtrim((string) config('services.anthropic.base_url'), '/');
    }

    /**
     * Call Claude, forcing $toolName so the answer is the tool's structured input.
     *
     * @return array<string,mixed>
     */
    public function structured(string $system, string $userMessage, array $tool, string $toolName, int $maxTokens = 3000): array
    {
        $res = Http::withHeaders([
            'x-api-key' => $this->key(),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post($this->base().'/messages', [
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $userMessage]],
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ]);

        if (!$res->successful()) {
            throw new RuntimeException("Anthropic API error ({$res->status()}): ".$res->body());
        }

        foreach ($res->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                return $block['input'] ?? [];
            }
        }

        throw new RuntimeException('Anthropic returned no tool output: '.$res->body());
    }
}
