<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\AiChat;
use App\Services\GeminiClient;
use App\Services\OpenAiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Truri AI i platformës (task #407 + dera e përbashkët #408, vendimet e
 * Marjusit): çelësat qendrorë (Gemini + OpenAI), zgjedhja e providerit
 * (default global + mbivendosje per-tenant — "ska rendesi provideri, neser
 * mund ta ndryshoj UNE jo hoteli") dhe rezerva ndër-provider. Hotelet s'kanë
 * asnjë fushë; paneli i tenantit vetëm e PËRDOR trurin.
 */
class AiController extends Controller
{
    public function index(GeminiClient $gemini, OpenAiClient $openai, AiChat $ai): Response
    {
        // Never ship a raw key — hint only, same rule as the currency key.
        $storedGemini = trim((string) PlatformSetting::get('ai.gemini_key', ''));
        $storedOpenai = trim((string) PlatformSetting::get('ai.openai_key', ''));

        // Shëndeti nga kontrolli ditor — vlen VETËM për çelësin aktual (key_fp,
        // gjetje Codex PR #514): rezultati i një çelësi të ndërruar s'shfaqet.
        $health = PlatformSetting::get('ai.gemini_key_health');
        $currentFp = hash('sha256', (string) $gemini->key());
        $healthOut = is_array($health) && ($health['key_fp'] ?? null) === $currentFp ? [
            'ok' => (bool) ($health['ok'] ?? false),
            'checked_at' => (string) ($health['checked_at'] ?? ''),
            'error' => $health['error'] ?? null,
        ] : null;

        $overrides = PlatformSetting::get('ai.provider_overrides');
        $overrides = is_array($overrides) ? $overrides : [];

        return Inertia::render('SuperAdmin/Ai', [
            'ai' => [
                'configured' => $gemini->configured(),
                'key_hint' => $storedGemini !== '' ? str_repeat('•', 6).substr($storedGemini, -4) : null,
                'from_env' => $storedGemini === '' && ! empty(config('services.gemini.key')),
                'env_key_present' => ! empty(config('services.gemini.key')),
                'model' => $gemini->model(),
                'fallback_model' => (string) config('services.gemini.fallback_model'),
                'health' => $healthOut,
            ],
            'openai' => [
                'configured' => $openai->configured(),
                'key_hint' => $storedOpenai !== '' ? str_repeat('•', 6).substr($storedOpenai, -4) : null,
                'from_env' => $storedOpenai === '' && ! empty(config('services.openai.key')),
                'env_key_present' => ! empty(config('services.openai.key')),
                'model' => $openai->model(),
            ],
            'providers' => [
                'default' => (string) PlatformSetting::get('ai.provider_default', 'gemini'),
                'cross_fallback' => (bool) PlatformSetting::get('ai.cross_provider_fallback', false),
                'overrides' => (object) $overrides,
                'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name, 'effective' => $ai->provider($t->id)]),
                'options' => AiChat::PROVIDERS,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'gemini_key' => ['nullable', 'string', 'max:200'],
            'clear_key' => ['nullable', 'boolean'],
            'openai_key' => ['nullable', 'string', 'max:200'],
            'clear_openai_key' => ['nullable', 'boolean'],
            'provider_default' => ['nullable', Rule::in(AiChat::PROVIDERS)],
            'cross_fallback' => ['nullable', 'boolean'],
            'provider_overrides' => ['nullable', 'array'],
            'provider_overrides.*' => ['nullable', Rule::in([...AiChat::PROVIDERS, ''])],
            // Task #409: koeficienti i faturimit + çmimorja — numrat i vendos
            // VETËM super-admini; kodi mban thjesht default-et.
            'billing_coefficient' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'pricing_overrides' => ['nullable', 'array'],
            'pricing_overrides.*.input' => ['required_with:pricing_overrides.*', 'numeric', 'min:0', 'max:100000'],
            'pricing_overrides.*.output' => ['required_with:pricing_overrides.*', 'numeric', 'min:0', 'max:100000'],
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

        if ($request->boolean('clear_openai_key')) {
            PlatformSetting::set('ai.openai_key', '', 'text');

            return back()->with('success', empty(config('services.openai.key'))
                ? 'Çelësi OpenAI u hoq — provideri openai s\'është më i disponueshëm.'
                : 'Çelësi OpenAI i ruajtur u hoq — platforma tani përdor çelësin e serverit (.env).');
        }

        $saved = [];

        $geminiKey = trim((string) ($data['gemini_key'] ?? ''));
        if ($geminiKey !== '') {
            PlatformSetting::set('ai.gemini_key', $geminiKey, 'text');
            // Çelës i RI = shëndeti i të vjetrit s'vlen më (Codex PR #511).
            PlatformSetting::set('ai.gemini_key_health', '', 'text');
            $saved[] = 'çelësi Gemini';
        }

        $openaiKey = trim((string) ($data['openai_key'] ?? ''));
        if ($openaiKey !== '') {
            PlatformSetting::set('ai.openai_key', $openaiKey, 'text');
            $saved[] = 'çelësi OpenAI';
        }

        if ($request->filled('provider_default')) {
            PlatformSetting::set('ai.provider_default', (string) $data['provider_default'], 'text');
            $saved[] = 'provideri default';
        }

        if ($request->has('cross_fallback')) {
            PlatformSetting::set('ai.cross_provider_fallback', $request->boolean('cross_fallback') ? '1' : '0', 'boolean');
            $saved[] = 'rezerva ndër-provider';
        }

        if ($request->has('provider_overrides')) {
            // Ruhen VETËM mbivendosjet e vlefshme jo-bosh — bosh = ndiq default-in.
            $overrides = collect($data['provider_overrides'] ?? [])
                ->filter(fn ($p) => in_array($p, AiChat::PROVIDERS, true))
                ->all();
            PlatformSetting::set('ai.provider_overrides', $overrides, 'json');
            $saved[] = 'mbivendosjet per-hotel';
        }

        if ($request->filled('billing_coefficient')) {
            PlatformSetting::set('ai.billing_coefficient', (string) (float) $data['billing_coefficient'], 'text');
            $saved[] = 'koeficienti i faturimit';
        }

        if ($request->has('pricing_overrides')) {
            $pricing = collect($data['pricing_overrides'] ?? [])
                ->map(fn ($p) => ['input' => (float) $p['input'], 'output' => (float) $p['output']])
                ->all();
            PlatformSetting::set('ai.pricing_overrides', $pricing, 'json');
            $saved[] = 'çmimorja e modeleve';
        }

        return back()->with('success', $saved === []
            ? 'Asnjë ndryshim.'
            : 'U ruajt: '.implode(', ', $saved).'.');
    }

    /**
     * Raporti "Përdorimi AI" (task #409): per-tenant për muajin e zgjedhur —
     * thirrje, tokena, kosto reale (mikro-USD → USD) dhe vlera e faturueshme
     * (× koeficienti që cakton super-admini). Leximi ndër-tenant OPT-IN i
     * shprehur me withoutGlobalScope: pa kontekst scope-i mbyllet në
     * tenant_id=0 (fail-closed — forcimi i auditit multi-tenant), dhe
     * control-plane-i duhet ta deklarojë qëllimin vetë.
     */
    public function usage(Request $request, \App\Services\AiUsageRecorder $recorder): Response
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->addMonth();

        $rows = \App\Models\AiUsageEvent::query()->withoutGlobalScope('tenant')
            ->selectRaw('tenant_id, provider, model, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens + thinking_tokens) as output_tokens, sum(cost_micro_usd) as cost_micro_usd')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('tenant_id', 'provider', 'model')
            ->get();

        $tenants = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->unique())
            ->pluck('name', 'id');
        $coefficient = $recorder->billingCoefficient();

        $byTenant = $rows->groupBy('tenant_id')->map(function ($group, $tenantId) use ($tenants, $coefficient) {
            $cost = (int) $group->sum('cost_micro_usd');

            return [
                'tenant_id' => (int) $tenantId,
                'tenant' => (string) ($tenants[$tenantId] ?? ('#'.$tenantId)),
                'calls' => (int) $group->sum('calls'),
                'input_tokens' => (int) $group->sum('input_tokens'),
                'output_tokens' => (int) $group->sum('output_tokens'),
                'cost_usd' => round($cost / 1_000_000, 4),
                'billable_usd' => round($cost * $coefficient / 1_000_000, 4),
                'models' => $group->map(fn ($r) => [
                    'provider' => $r->provider,
                    'model' => $r->model,
                    'calls' => (int) $r->calls,
                    'cost_usd' => round(((int) $r->cost_micro_usd) / 1_000_000, 4),
                ])->values(),
            ];
        })->sortByDesc('cost_usd')->values();

        // Çmimorja për editorin (gjetje Codex #569 P1): përveç default-eve të
        // config dhe mbivendosjeve, hyjnë edhe modelet AKTIVE të konfiguruara
        // dhe ÇDO model i VËZHGUAR në matje — një model i panjohur që po
        // regjistrohet me kosto 0 duhet të marrë çmim nga super-admini, jo
        // të mbetet falas përgjithmonë.
        $overrides = PlatformSetting::get('ai.pricing_overrides');
        $overrides = is_array($overrides) ? $overrides : [];
        $defaults = config('services.ai.pricing', []);
        $models = collect($defaults)->keys()
            ->merge(array_keys($overrides))
            ->merge([
                (string) config('services.gemini.model'),
                (string) config('services.gemini.fallback_model'),
                (string) config('services.openai.model'),
            ])
            ->merge(\App\Models\AiUsageEvent::query()->withoutGlobalScope('tenant')->select('model')->distinct()->pluck('model'))
            ->filter()
            ->unique()
            ->values();
        // is_override + default i veçuar (gjetje Codex #569 P1): UI dërgon si
        // mbivendosje VETËM rreshtat realisht të mbivendosur — default-et e
        // paprekur mbeten te config dhe përditësimet e ardhshme s'maskohen.
        $pricing = $models->mapWithKeys(fn (string $model) => [$model => $recorder->priceFor($model) + [
            'is_override' => array_key_exists($model, $overrides),
            'default' => $defaults[$model] ?? null,
        ]]);

        return Inertia::render('SuperAdmin/AiUsage', [
            'month' => $month,
            'months' => collect(range(0, 5))->map(fn (int $i) => now()->subMonths($i)->format('Y-m'))->values(),
            'coefficient' => $coefficient,
            'rows' => $byTenant,
            'totals' => [
                'calls' => (int) $rows->sum('calls'),
                'cost_usd' => round(((int) $rows->sum('cost_micro_usd')) / 1_000_000, 4),
                'billable_usd' => round(((int) $rows->sum('cost_micro_usd')) * $coefficient / 1_000_000, 4),
            ],
            'pricing' => (object) $pricing->all(),
        ]);
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
