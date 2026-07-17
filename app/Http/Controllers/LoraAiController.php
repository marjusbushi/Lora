<?php

namespace App\Http\Controllers;

use App\Models\AiAccessToken;
use App\Models\AiOAuthGrant;
use App\Models\Setting;
use App\Services\AiOAuthGrantManager;
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
            'settings' => [
                'reservations_enabled' => $this->boolSetting('reservations_enabled', true),
                'messages_enabled' => $this->boolSetting('messages_enabled', true),
                'guest_reply_enabled' => $this->boolSetting('guest_reply_enabled', true),
                'pricing_enabled' => $this->boolSetting('pricing_enabled', true),
                'price_apply_enabled' => $this->boolSetting('price_apply_enabled', false),
            ],
            'modules' => [
                'channel_manager' => $billing->enabled(TenantBillingService::CHANNEL_MANAGER, $tenant),
                'smart_pricing' => $billing->enabled(TenantBillingService::SMART_PRICING, $tenant),
            ],
            'recentActions' => DB::table('audit_logs')
                ->where('tenant_id', $tenant->id)->where('source', 'ai')
                ->latest('id')->limit(6)->get(['action', 'created_at']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reservations_enabled' => ['required', 'boolean'],
            'messages_enabled' => ['required', 'boolean'],
            'guest_reply_enabled' => ['required', 'boolean'],
            'pricing_enabled' => ['required', 'boolean'],
            'price_apply_enabled' => ['required', 'boolean'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set('ai_mcp.'.$key, $value, 'boolean');
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
