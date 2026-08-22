<?php

namespace App\Services;

use App\Contracts\AiChatProvider;
use App\Models\PlatformSetting;
use App\Tenancy\TenantContext;
use RuntimeException;

/**
 * DERA E PËRBASHKËT (task #408): zgjedh shoferin AI për tenantin aktual dhe,
 * me flamurin e ndezur, jep rezervën NDËR-PROVIDER — vdekja e vërtetë e
 * 503-ve: Google dhe OpenAI nuk sëmuren kurrë në të njëjtën ditë.
 *
 * VENDIMI I PROVIDERIT ËSHTË I PLATFORMËS, JO I HOTELIT (Marjusi 2026-08-22:
 * "ska rendesi provideri — neser mund ta ndryshoj UNE jo hoteli"): default
 * global + mbivendosje per-tenant, të dyja PlatformSetting të editueshme
 * VETËM te super-admin → Truri AI. Paneli i hotelit s'sheh kurrë emra.
 */
class AiChat
{
    public const PROVIDERS = ['gemini', 'openai'];

    /** Provideri EFEKTIV për tenantin e dhënë (ose atë të kontekstit aktual). */
    public function provider(?int $tenantId = null): string
    {
        $tenantId ??= app(TenantContext::class)->tenant()?->id;

        $overrides = PlatformSetting::get('ai.provider_overrides');
        $chosen = is_array($overrides) && $tenantId !== null
            ? (string) ($overrides[(string) $tenantId] ?? $overrides[$tenantId] ?? '')
            : '';
        if (! in_array($chosen, self::PROVIDERS, true)) {
            $chosen = (string) PlatformSetting::get('ai.provider_default', 'gemini');
        }

        return in_array($chosen, self::PROVIDERS, true) ? $chosen : 'gemini';
    }

    /** Shoferi i një provideri — GJITHMONË nga container-i (testet i mock-ojnë klientët). */
    public function driver(?string $provider = null): AiChatProvider
    {
        return match ($provider ?? $this->provider()) {
            'openai' => app(OpenAiClient::class),
            default => app(GeminiClient::class),
        };
    }

    public function configured(): bool
    {
        return $this->driver()->configured();
    }

    /**
     * Biseda me mjete përmes providerit të tenantit; me flamurin
     * 'ai.cross_provider_fallback' ON, një dështim KALIMTAR (5xx/timeout —
     * pra edhe pas rezervës së brendshme të Gemini-t, task #403) provohet
     * NJË herë te provideri tjetër i konfiguruar. Ekzekutuesit janë
     * idempotentë (holdi ripërdoret, leximet s'kanë efekt) — rinisja e
     * bisedës nga e para është e sigurt, njësoj si riprova e job-it.
     * Dështon edhe tjetri → bublon gabimi ORIGJINAL.
     *
     * @param  array<int,array{name:string,description?:string,input_schema?:array}>  $tools
     * @param  array<string,callable(array):array>  $executors
     * @return array{args:array<string,mixed>,toolsUsed:array<int,string>,usage:array{input:int,output:int,thinking:int,provider:string,model:string}}
     */
    public function converse(string $system, string $userMessage, array $tools, array $executors, string $finalToolName, int $maxTokens = 2048, int $timeoutSeconds = 60, int $maxToolRounds = 3): array
    {
        $primary = $this->provider();

        try {
            return $this->driver($primary)->converse($system, $userMessage, $tools, $executors, $finalToolName, $maxTokens, $timeoutSeconds, $maxToolRounds);
        } catch (RuntimeException $e) {
            $transient = (bool) preg_match('/gabim \((5\d\d)\)|\(timeout\)/u', $e->getMessage());
            $other = $primary === 'gemini' ? 'openai' : 'gemini';

            if (! $transient
                || ! (bool) PlatformSetting::get('ai.cross_provider_fallback', false)
                || ! $this->driver($other)->configured()) {
                throw $e;
            }

            try {
                return $this->driver($other)->converse($system, $userMessage, $tools, $executors, $finalToolName, $maxTokens, $timeoutSeconds, $maxToolRounds);
            } catch (\Throwable) {
                // Rezerva ndër-provider dështoi edhe ajo — gabimi ORIGJINAL
                // bublon (shkalla e riprovës së job-it vazhdon si zakonisht).
                throw $e;
            }
        }
    }
}
