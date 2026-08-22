<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\GeminiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Truri AI i platformës (task #407, vendimi i Marjusit): NJË çelës qendror
 * Gemini shërben çdo hotel — njësoj si çelësi i kursit të këmbimit. Hotelet
 * s'kanë më asnjë fushë çelësi; paneli i tenantit vetëm e PËRDOR trurin.
 * Çelësi ruhet në PlatformSetting 'ai.gemini_key'; env GEMINI_API_KEY mbetet
 * si rrugë rezervë (GeminiClient::key()).
 */
class AiController extends Controller
{
    public function index(GeminiClient $gemini): Response
    {
        // Never ship the raw key — hint only, same rule as the currency key.
        $storedKey = trim((string) PlatformSetting::get('ai.gemini_key', ''));
        $fromEnv = $storedKey === '' && ! empty(config('services.gemini.key'));

        // Shëndeti nga kontrolli ditor — vlen VETËM për çelësin aktual (key_fp,
        // gjetje Codex PR #514): rezultati i një çelësi të ndërruar s'shfaqet.
        $health = PlatformSetting::get('ai.gemini_key_health');
        $currentFp = hash('sha256', (string) $gemini->key());
        $healthOut = is_array($health) && ($health['key_fp'] ?? null) === $currentFp ? [
            'ok' => (bool) ($health['ok'] ?? false),
            'checked_at' => (string) ($health['checked_at'] ?? ''),
            'error' => $health['error'] ?? null,
        ] : null;

        return Inertia::render('SuperAdmin/Ai', [
            'ai' => [
                'configured' => $gemini->configured(),
                'key_hint' => $storedKey !== '' ? str_repeat('•', 6).substr($storedKey, -4) : null,
                'from_env' => $fromEnv,
                // Heqja e çelësit të ruajtur NUK e fik trurin kur serveri ka
                // çelës në .env (rezerva e GeminiClient::key) — UI-ja duhet ta
                // thotë të vërtetën, jo "FIK platformën" (gjetje Codex #559).
                'env_key_present' => ! empty(config('services.gemini.key')),
                'model' => $gemini->model(),
                'fallback_model' => (string) config('services.gemini.fallback_model'),
                'health' => $healthOut,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'gemini_key' => ['nullable', 'string', 'max:200'],
            'clear_key' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('clear_key')) {
            PlatformSetting::set('ai.gemini_key', '', 'text');
            // Pa çelës s'ka çfarë kontrollohet — alarmi i vjetër hiqet menjëherë.
            PlatformSetting::set('ai.gemini_key_health', '', 'text');

            // E vërteta e plotë (gjetje Codex #559): me çelës serveri në .env,
            // heqja e të ruajturit s'e fik trurin — platforma kalon te ai.
            return back()->with('success', empty(config('services.gemini.key'))
                ? 'Çelësi qendror AI u hoq — truri AI është FIKUR për gjithë platformën.'
                : 'Çelësi i ruajtur u hoq — platforma tani përdor çelësin e serverit (.env); truri AI mbetet aktiv.');
        }

        $key = trim((string) ($data['gemini_key'] ?? ''));
        if ($key === '') {
            return back()->with('success', 'Asnjë ndryshim — fusha ishte bosh.');
        }

        PlatformSetting::set('ai.gemini_key', $key, 'text');
        // Çelës i RI = shëndeti i të vjetrit s'vlen më (gjetje Codex, PR #511):
        // pa këtë, panelet do e quanin të prishur çelësin e ri deri në
        // kontrollin e radhës ditor (06:30), i cili e rimbush.
        PlatformSetting::set('ai.gemini_key_health', '', 'text');

        return back()->with('success', 'Çelësi qendror AI u ruajt — truri i Lorës është aktiv për gjithë hotelet.');
    }

    /** "Kontrollo tani" — verifikim i menjëhershëm që super-admini të mos presë 06:30-ën. */
    public function check(): RedirectResponse
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('gemini:check-key');
        $output = trim(\Illuminate\Support\Facades\Artisan::output());

        return $exit === 0
            ? back()->with('success', $output !== '' ? $output : 'Kontrolli u krye.')
            : back()->with('error', $output !== '' ? $output : 'Kontrolli dështoi.');
    }
}
