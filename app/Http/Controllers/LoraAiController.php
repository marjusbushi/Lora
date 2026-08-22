<?php

namespace App\Http\Controllers;

use App\Models\AiAccessToken;
use App\Models\AiOAuthGrant;
use App\Models\Setting;
use App\Services\AiOAuthGrantManager;
use App\Services\AiPriceGuardrails;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LoraAiController extends Controller
{
    public function index(Request $request, TenantBillingService $billing): Response
    {
        $tenant = app(TenantContext::class)->tenant();
        $bindings = AiAccessToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->pluck('access_token_id');
        $grants = AiOAuthGrant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id);
        $hasGrant = (clone $grants)->exists();
        $grantClientIds = $grants->pluck('client_id');
        $grantBindings = AiAccessToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->whereIn('client_id', $grantClientIds)
            ->pluck('access_token_id');
        $liveAccess = $grantBindings->isNotEmpty() && DB::table('oauth_access_tokens')
            ->whereIn('id', $grantBindings)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->exists();
        $liveRefresh = $grantBindings->isNotEmpty() && DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $grantBindings)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->exists();
        $connected = $hasGrant && ($liveAccess || $liveRefresh);

        return Inertia::render('LoraAi/Index', [
            'connection' => [
                'connected' => $connected,
                'revocable' => $hasGrant || $bindings->isNotEmpty(),
                'endpoint' => url('/mcp/lora-hotel'),
                'chatgptUrl' => config('services.openai.chatgpt_connect_url', 'https://chatgpt.com/'),
                'hotel' => $tenant->name,
            ],
            // Shëndeti i çelësit Gemini (task #382): kontrolli ditor e shkruan;
            // paneli paralajmëron VETËM kur ok=false — para se Lora të heshtë.
            'geminiKeyHealth' => (function () {
                $health = Setting::get('ai.gemini_key_health');

                // Alarmi vlen VETËM për çelësin AKTUAL (gjetje Codex, PR #514):
                // gjurma e çelësit të testuar duhet të përputhet — një rezultat
                // i shkruar vonë për një çelës të ndërruar nuk shfaqet kurrë.
                $currentFp = hash('sha256', (string) app(\App\Services\GeminiClient::class)->key());

                return is_array($health)
                    && ($health['ok'] ?? null) === false
                    && ($health['key_fp'] ?? null) === $currentFp ? [
                        'error' => (string) ($health['error'] ?? ''),
                        'checked_at' => (string) ($health['checked_at'] ?? ''),
                    ] : null;
            })(),
            'aiSettings' => [
                'universal_search_enabled' => $this->boolSetting('universal_search_enabled', true),
                'reservations_enabled' => $this->boolSetting('reservations_enabled', true),
                'messages_enabled' => $this->boolSetting('messages_enabled', true),
                'guest_reply_enabled' => $this->boolSetting('guest_reply_enabled', true),
                'guest_auto_reply_enabled' => $this->boolSetting('guest_auto_reply_enabled', true),
                // Identiteti & karakteri (task #370): vlera EFEKTIVE, kurrë bosh —
                // hoteli e para-gjen të mbushur me default-in tonë dhe e ndryshon vetë.
                'assistant_name' => trim((string) Setting::get('ai_mcp.assistant_name')) ?: \App\Jobs\GenerateAiGuestReply::DEFAULT_ASSISTANT_NAME,
                'assistant_character' => trim((string) Setting::get('ai_mcp.assistant_character')) ?: \App\Jobs\GenerateAiGuestReply::DEFAULT_ASSISTANT_CHARACTER,
                // WhatsApp: default FIKUR — rruga QR-lite mban risk bllokimi nga
                // Meta, roboti aty ndizet vetëm me dorën e pronarit (task #337).
                'whatsapp_auto_reply_enabled' => $this->boolSetting('whatsapp_auto_reply_enabled', false),
                // Hapi 3 (task #365): Lora rezervon vetë nga biseda — default
                // FIKUR; e ndez pronari vetëm kur POK-u është gati për pagesa.
                'whatsapp_booking_enabled' => $this->boolSetting('whatsapp_booking_enabled', false),
                'pricing_enabled' => $this->boolSetting('pricing_enabled', true),
                'ai_price_recommendations_enabled' => $this->boolSetting('ai_price_recommendations_enabled', true),
                'price_apply_enabled' => $this->boolSetting('price_apply_enabled', false),
                'finance_enabled' => $this->boolSetting('finance_enabled', false),
                'housekeeping_enabled' => $this->boolSetting('housekeeping_enabled', false),
                'maintenance_enabled' => $this->boolSetting('maintenance_enabled', false),
                'pos_enabled' => $this->boolSetting('pos_enabled', false),
                'inventory_enabled' => $this->boolSetting('inventory_enabled', false),
            ],
            'aiModules' => [
                'channel_manager' => $billing->enabled(TenantBillingService::CHANNEL_MANAGER, $tenant),
                'smart_pricing' => $billing->enabled(TenantBillingService::SMART_PRICING, $tenant),
                'finance' => $billing->enabled(TenantBillingService::FINANCE, $tenant),
                'housekeeping' => $billing->enabled(TenantBillingService::HOUSEKEEPING, $tenant),
                'pos' => $billing->enabled(TenantBillingService::POS, $tenant),
            ],
            'pricingPolicy' => [
                'maxDeviationPct' => AiPriceGuardrails::maxDeviationPct(),
            ],
            'recentActions' => DB::table('audit_logs')
                ->where('tenant_id', $tenant->id)->where('source', 'ai')
                ->latest('id')->limit(6)->get(['action', 'created_at']),
            // Kartela e Lorës (task #402): vlera e saj e dukshme — jo konfigurim.
            'stats' => (function () use ($tenant, $request) {
                $monthStart = now()->startOfMonth();
                $monthly = DB::table('audit_logs')
                    ->where('tenant_id', $tenant->id)->where('source', 'ai')
                    ->where('created_at', '>=', $monthStart);

                $confirmedIds = (clone $monthly)
                    ->where('action', 'message.ai_booking_confirmed')
                    ->where('subject_type', \App\Models\Reservation::class)
                    ->pluck('subject_id')->filter();

                // Të ardhurat janë sipërfaqe financiare — rruga mbrohet vetëm me
                // view_settings, ndaj shuma jepet vetëm kujt sheh paratë (Codex, PR #542).
                $seesMoney = $request->user()->can('view_financials') || $request->user()->can('view_finance');

                return [
                    'replies' => (clone $monthly)
                        ->whereIn('action', ['message.ai_reply', 'ai.guest_reply.sent'])
                        ->count(),
                    'bookings' => $confirmedIds->count(),
                    // Shuma nga snapshot-i i ngrirë në monedhën BAZË të hotelit
                    // (total_amount_base) — properties.total mban monedhën e shitjes
                    // të momentit dhe një ndryshim monedhe brenda muajit do t'i
                    // përzinte shifrat (Codex, PR #548).
                    // withTrashed: numërimi vjen nga audit-i append-only, ndaj
                    // edhe shuma duhet ta mbajë rezervimin e fshirë më vonë —
                    // statistika e muajit s'zbrazet nga fshirjet (Codex, PR #549).
                    'bookingRevenue' => $seesMoney ? round((float) \App\Models\Reservation::withTrashed()
                        ->whereIn('id', $confirmedIds)
                        ->sum('total_amount_base'), 2) : null,
                ];
            })(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'universal_search_enabled' => ['sometimes', 'boolean'],
            'reservations_enabled' => ['required', 'boolean'],
            'messages_enabled' => ['required', 'boolean'],
            'guest_reply_enabled' => ['required', 'boolean'],
            'guest_auto_reply_enabled' => ['sometimes', 'boolean'],
            'whatsapp_auto_reply_enabled' => ['sometimes', 'boolean'],
            'whatsapp_booking_enabled' => ['sometimes', 'boolean'],
            'assistant_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'assistant_character' => ['sometimes', 'nullable', 'string', 'max:600'],
            'pricing_enabled' => ['required', 'boolean'],
            'ai_price_recommendations_enabled' => ['sometimes', 'boolean'],
            'price_apply_enabled' => ['required', 'boolean'],
            'finance_enabled' => ['sometimes', 'boolean'],
            'housekeeping_enabled' => ['sometimes', 'boolean'],
            'maintenance_enabled' => ['sometimes', 'boolean'],
            'pos_enabled' => ['sometimes', 'boolean'],
            'inventory_enabled' => ['sometimes', 'boolean'],
        ]);

        // Zinxhiri i varësive zbatohet edhe në SERVER — job-et (p.sh.
        // GenerateAiGuestReply) lexojnë çelësin e vet direkt, ndaj një vlerë e
        // varur e mbetur ndezur do t'i mbante aktive pas fikjes së nivelit nën
        // të (gjetje Codex, PR #542). UI-ja kaskadon vetë; kjo është rrjeta.
        if (! ($data['messages_enabled'] ?? true)) {
            $data['guest_reply_enabled'] = false;
        }
        if (! ($data['guest_reply_enabled'] ?? true)) {
            $data['guest_auto_reply_enabled'] = false;
            $data['whatsapp_auto_reply_enabled'] = false;
        }
        // Prindi 'whatsapp_auto_reply_enabled' është opsional në payload — kur
        // mungon, konsultohet vlera e RUAJTUR (jo një default optimist), që një
        // kërkesë e pjesshme të mos ruajë booking=ON mbi një prind të fikur
        // (Codex, PR #548).
        $whatsAppAutoOn = array_key_exists('whatsapp_auto_reply_enabled', $data)
            ? (bool) $data['whatsapp_auto_reply_enabled']
            : $this->boolSetting('whatsapp_auto_reply_enabled', false);
        if (! $whatsAppAutoOn) {
            $data['whatsapp_booking_enabled'] = false;
        }
        if (! ($data['pricing_enabled'] ?? true)) {
            $data['price_apply_enabled'] = false;
            $data['ai_price_recommendations_enabled'] = false;
        }

        // Identiteti/karakteri janë TEKST — jo çelësa on/off (task #370). Bosh →
        // ruhet '' dhe job-i bie vetë te default-i i para-shkruar.
        $stringKeys = ['assistant_name', 'assistant_character'];
        foreach ($data as $key => $value) {
            in_array($key, $stringKeys, true)
                ? Setting::set('ai_mcp.'.$key, trim((string) $value), 'string')
                : Setting::set('ai_mcp.'.$key, $value, 'boolean');
        }

        return back()->with('success', 'Lejet e Lora AI u ruajtën.');
    }

    public function disconnect(Request $request, AiOAuthGrantManager $grants): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->requireId();
        $grants->disconnectTenant($request->user()->id, $tenantId);

        return back()->with('success', 'Lidhja me ChatGPT u shkëput.');
    }

    private function boolSetting(string $key, bool $default): bool
    {
        return filter_var(Setting::get('ai_mcp.'.$key, $default), FILTER_VALIDATE_BOOL);
    }
}
