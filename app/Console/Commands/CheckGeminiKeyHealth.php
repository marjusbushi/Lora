<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Models\Setting;
use App\Services\GeminiClient;
use Illuminate\Console\Command;

/**
 * Kontrolli ditor i shëndetit të çelësit Gemini PER-HOTEL (task #382, miratuar
 * nga Marjus): çelësi i një hoteli mund të skadojë a revokohet dhe sot kjo
 * zbulohej vetëm kur Lora heshtte me mysafirë realë. Një thirrje metadata
 * pothuaj-falas (0 tokena) e verifikon; rezultati ruhet per-tenant dhe paneli
 * /pms/lora-ai i tregon hotelit paralajmërimin PARA se t'i ndodhë live.
 *
 * Ekzekutohet nga scheduler-i përmes TenantCommandRunner (kontekst per tenant);
 * manualisht kërkon --tenant= (kurrë fallback te tenant-i i parë).
 */
class CheckGeminiKeyHealth extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'gemini:check-key {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Verifikon çelësin Gemini të hotelit me një thirrje metadata dhe ruan gjendjen për panelin.';

    public function handle(GeminiClient $gemini): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! $gemini->configured()) {
            // Pa çelës s'ka as thirrje, as paralajmërim — gjendja e vjetër fshihet
            // që një çelës i hequr të mos mbajë alarm të vjetruar.
            Setting::set('ai.gemini_key_health', '', 'text');
            $this->info('Pa çelës Gemini të konfiguruar — asnjë kontroll.');

            return self::SUCCESS;
        }

        $checkedKey = $gemini->key();
        $health = $gemini->healthCheck();

        if ($health['ok'] === null) {
            // Kalimtar (429/5xx/rrjet) — mos e shëno të prishur, mbaj gjendjen e fundit.
            $this->info('Përgjigje kalimtare nga Google — gjendja e mëparshme ruhet.');

            return self::SUCCESS;
        }

        // Admini e ndërroi/hoqi çelësin NDËRSA kërkesa ishte në fluturim
        // (gjetje Codex, PR #512): rezultati i çelësit të vjetër s'guxon të
        // mbishkruajë pastrimin që bëri updateAi() — hidhet poshtë.
        if ($gemini->key() !== $checkedKey) {
            $this->info('Çelësi ndryshoi gjatë kontrollit — rezultati u hodh poshtë.');

            return self::SUCCESS;
        }

        Setting::set('ai.gemini_key_health', [
            'ok' => $health['ok'],
            'checked_at' => now()->toIso8601String(),
            'error' => $health['error'],
        ], 'json');

        $this->info($health['ok'] ? 'Çelësi Gemini punon.' : 'Çelësi Gemini DËSHTOI: '.$health['error']);

        return self::SUCCESS;
    }
}
