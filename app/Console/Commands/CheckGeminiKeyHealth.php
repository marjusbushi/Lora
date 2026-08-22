<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Services\GeminiClient;
use Illuminate\Console\Command;

/**
 * Kontrolli ditor i shëndetit të çelësit qendror Gemini (task #382; GLOBAL
 * nga task #407 — vendimi i Marjusit: NJË çelës platforme për të gjithë
 * hotelet, si çelësi i kursit të këmbimit). Çelësi mund të skadojë a
 * revokohet dhe kjo zbulohej vetëm kur Lora heshtte me mysafirë realë. Një
 * thirrje metadata pothuaj-falas (0 tokena) e verifikon; rezultati ruhet në
 * PlatformSetting dhe panelet (/pms/lora-ai + super-admin) e tregojnë PARA
 * se t'i ndodhë live.
 *
 * Ekzekutohet nga scheduler-i NJËHERË globalisht — çelësi s'është më
 * per-tenant, ndaj s'ka nevojë për kontekst tenant-i.
 */
class CheckGeminiKeyHealth extends Command
{
    protected $signature = 'gemini:check-key';

    protected $description = 'Verifikon çelësin qendror Gemini të platformës me një thirrje metadata dhe ruan gjendjen për panelet.';

    public function handle(GeminiClient $gemini): int
    {
        if (! $gemini->configured()) {
            // Pa çelës s'ka as thirrje, as paralajmërim — gjendja e vjetër fshihet
            // që një çelës i hequr të mos mbajë alarm të vjetruar.
            PlatformSetting::set('ai.gemini_key_health', '', 'text');
            $this->info('Pa çelës qendror Gemini të konfiguruar — asnjë kontroll.');

            return self::SUCCESS;
        }

        $checkedKey = $gemini->key();
        $health = $gemini->healthCheck();

        if ($health['ok'] === null) {
            // Kalimtar (429/5xx/rrjet) — mos e shëno të prishur, mbaj gjendjen e fundit.
            $this->info('Përgjigje kalimtare nga Google — gjendja e mëparshme ruhet.');

            return self::SUCCESS;
        }

        // Super-admini e ndërroi/hoqi çelësin NDËRSA kërkesa ishte në fluturim
        // (gjetje Codex, PR #512): rezultati i çelësit të vjetër s'guxon të
        // mbishkruajë pastrimin që bëri ruajtja e çelësit — hidhet poshtë.
        if ($gemini->key() !== $checkedKey) {
            $this->info('Çelësi ndryshoi gjatë kontrollit — rezultati u hodh poshtë.');

            return self::SUCCESS;
        }

        // key_fp e bën garën të parrezikshme NGA ANA E LEXUESIT (gjetje Codex,
        // PR #514): edhe nëse ky shkrim ulet një çast PAS një ndërrimi çelësi,
        // panelet e shfaqin alarmin VETËM kur gjurma përputhet me çelësin
        // aktual — një rezultat i vjetruar s'ka më fuqi, pavarësisht kur shkruhet.
        PlatformSetting::set('ai.gemini_key_health', [
            'ok' => $health['ok'],
            'checked_at' => now()->toIso8601String(),
            'error' => $health['error'],
            'key_fp' => hash('sha256', (string) $checkedKey),
        ], 'json');

        $this->info($health['ok'] ? 'Çelësi qendror Gemini punon.' : 'Çelësi qendror Gemini DËSHTOI: '.$health['error']);

        return self::SUCCESS;
    }
}
