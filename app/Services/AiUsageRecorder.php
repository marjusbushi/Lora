<?php

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\PlatformSetting;
use App\Tenancy\TenantContext;

/**
 * Regjistruesi i përdorimit AI (task #409): çdo thirrje AI → një rresht
 * ai_usage_events me tokenat dhe koston, që koeficienti i faturimit të
 * nxjerrë vlerën e pagesës per-tenant.
 *
 * FAIL-SAFE ME LIGJ: matja s'e prish KURRË përgjigjen e mysafirit — çdo
 * dështim kapet, raportohet dhe gëlltitet. Pa kontekst tenant-i (komandë
 * globale, kanari) → asnjë rresht, kurrë fallback te tenant-i i parë.
 */
class AiUsageRecorder
{
    /**
     * @param  array{input?:int,output?:int,thinking?:int,provider?:string,model?:string}  $usage  siç e kthen AiChatProvider::converse
     */
    public function record(array $usage, string $feature, ?int $threadId = null, ?int $messageId = null): void
    {
        try {
            if (! app(TenantContext::class)->tenant()) {
                return;
            }

            AiUsageEvent::create([
                'provider' => (string) ($usage['provider'] ?? 'unknown'),
                'model' => (string) ($usage['model'] ?? 'unknown'),
                'feature' => $feature,
                'input_tokens' => (int) ($usage['input'] ?? 0),
                'output_tokens' => (int) ($usage['output'] ?? 0),
                'thinking_tokens' => (int) ($usage['thinking'] ?? 0),
                'cost_micro_usd' => $this->costMicroUsd($usage),
                'message_thread_id' => $threadId,
                'message_id' => $messageId,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Çmimi efektiv i një modeli (USD per 1M tokena): mbivendosja e
     * super-adminit fiton mbi default-in e config-ut. Model i panjohur →
     * 0/0 — tokenat regjistrohen njësoj dhe raporti e nxjerr në pah.
     *
     * @return array{input: float, output: float}
     */
    public function priceFor(string $model): array
    {
        $overrides = PlatformSetting::get('ai.pricing_overrides');
        $override = is_array($overrides) ? ($overrides[$model] ?? null) : null;
        // KUJDES: emrat e modeleve mbajnë PIKA ('gemini-3.7-flash') — dot-noti
        // i config() do t'i ndante si nyje. Merret harta dhe indeksohet me çelës.
        $default = config('services.ai.pricing', [])[$model] ?? [];

        return [
            'input' => (float) ($override['input'] ?? $default['input'] ?? 0),
            'output' => (float) ($override['output'] ?? $default['output'] ?? 0),
        ];
    }

    /**
     * Kosto në MIKRO-USD si integer — 1 token me çmim $X/1M kushton saktësisht
     * X mikro-USD, pa asnjë float në para. "Mendimi" faturohet si output.
     */
    public function costMicroUsd(array $usage): int
    {
        $price = $this->priceFor((string) ($usage['model'] ?? ''));

        return (int) round(
            ((int) ($usage['input'] ?? 0)) * $price['input']
            + (((int) ($usage['output'] ?? 0)) + ((int) ($usage['thinking'] ?? 0))) * $price['output'],
        );
    }

    /** Koeficienti global i faturimit — NUMRIN e vendos Marjusi në super-admin (default 1.0 = kosto e pastër). */
    public function billingCoefficient(): float
    {
        $raw = PlatformSetting::get('ai.billing_coefficient');

        return is_numeric($raw) && (float) $raw >= 0 ? (float) $raw : 1.0;
    }
}
