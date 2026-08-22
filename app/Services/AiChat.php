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
     * NJË herë te provideri tjetër i konfiguruar. Dështon edhe tjetri →
     * bublon gabimi ORIGJINAL.
     *
     * EKZEKUTUESIT ME GJENDJE (gjetje Codex #566 P1): rezerva RINIS bisedën
     * nga e para, ndaj $executors pranohet edhe si FABRIKË (Closure): thirret
     * PER PROVË — thirrësi zeron mbledhësit e vet aty, dhe kthen NULL si
     * VETO kur prova e braktisur la efekte anësore të pakthyeshme (hold i
     * krijuar): atëherë rezerva hiqet dorë dhe gabimi origjinal bublon —
     * shkalla e riprovës së job-it e merr me ekzekutues idempotentë.
     *
     * @param  array<int,array{name:string,description?:string,input_schema?:array}>  $tools
     * @param  array<string,callable(array):array>|\Closure  $executors  array statik OSE fabrikë per-provë (kthen ?array)
     * @return array{args:array<string,mixed>,toolsUsed:array<int,string>,usage:array{input:int,output:int,thinking:int,provider:string,model:string}}
     */
    public function converse(string $system, string $userMessage, array $tools, array|\Closure $executors, string $finalToolName, int $maxTokens = 2048, int $timeoutSeconds = 60, int $maxToolRounds = 3, ?callable $onUsage = null): array
    {
        $primary = $this->provider();
        $resolve = fn (): ?array => $executors instanceof \Closure ? $executors() : $executors;

        $attempt = $resolve();
        if ($attempt === null) {
            throw new RuntimeException('Fabrika e ekzekutuesve nuk dha ekzekutues për provën e parë.');
        }

        try {
            // $onUsage kalon te ÇDO provë — edhe raundet e suksesshme të një
            // prove që dështon më vonë janë faturuar nga provideri dhe duhet
            // të mbeten në dëshmi (gjetje Codex #568 P1).
            return $this->driver($primary)->converse($system, $userMessage, $tools, $attempt, $finalToolName, $maxTokens, $timeoutSeconds, $maxToolRounds, $onUsage);
        } catch (RuntimeException $e) {
            $transient = (bool) preg_match('/gabim \((5\d\d)\)|\(timeout\)/u', $e->getMessage());
            $other = $primary === 'gemini' ? 'openai' : 'gemini';

            if (! $transient
                || ! (bool) PlatformSetting::get('ai.cross_provider_fallback', false)
                || ! $this->driver($other)->configured()) {
                throw $e;
            }

            $fresh = $resolve();
            if ($fresh === null) {
                // VETO nga fabrika: prova e braktisur la gjurmë të pakthyeshme
                // (hold POK) — më mirë riprova idempotente e job-it sesa një
                // përgjigje rezerve mbi gjendje gjysmake.
                throw $e;
            }

            try {
                return $this->driver($other)->converse($system, $userMessage, $tools, $fresh, $finalToolName, $maxTokens, $timeoutSeconds, $maxToolRounds, $onUsage);
            } catch (\Throwable) {
                // Rezerva ndër-provider dështoi edhe ajo — gabimi ORIGJINAL
                // bublon (shkalla e riprovës së job-it vazhdon si zakonisht).
                throw $e;
            }
        }
    }
}
