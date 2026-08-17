<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConnection;
use App\Services\WhatsAppBridgeClient;
use App\Services\WhatsAppMessageImporter;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * WhatsApp QR-lite (task MHQ #335): veprimet e panelit (lidh/status/shkëput)
 * flasin me urën Node; ngjarjet e urës hyjnë te event() — rrugë publike si
 * webhook-u i Channex, e mbrojtur me token të përbashkët + kryqëzim tenant-i.
 */
class WhatsAppController extends Controller
{
    /** Panel: nis lidhjen — ura kthen QR që useri e skanon me telefonin e hotelit. */
    public function connect(Request $request, WhatsAppBridgeClient $bridge, TenantContext $context): RedirectResponse
    {
        try {
            $bridge->start($context->tenant()->id, $request->getSchemeAndHttpHost().'/whatsapp/bridge/event');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        WhatsAppConnection::updateOrCreate([], [
            'status' => WhatsAppConnection::STATUS_PAIRING,
            'last_event_at' => now(),
        ]);

        return back()->with('success', 'Skano kodin QR me telefonin e hotelit.');
    }

    /** Panel (poll): statusi i gjallë + QR nga ura; ura offline s'është kurrë 500. */
    public function status(WhatsAppBridgeClient $bridge, TenantContext $context): JsonResponse
    {
        $row = WhatsAppConnection::query()->first();

        $live = null;
        $bridgeOffline = false;
        try {
            $live = $bridge->status($context->tenant()->id);
        } catch (\RuntimeException) {
            $bridgeOffline = true;
        }

        return response()->json([
            'status' => $live['status'] ?? $row?->status ?? WhatsAppConnection::STATUS_DISCONNECTED,
            'qr' => $live['qr'] ?? null,
            'phone' => $live['phone'] ?? $row?->phone_number,
            'bridge_offline' => $bridgeOffline,
            'last_event_at' => $row?->last_event_at?->toIso8601String(),
        ]);
    }

    /** Panel: shkëput numrin (logout në urë + pastrim i gjendjes lokale). */
    public function disconnect(WhatsAppBridgeClient $bridge, TenantContext $context): RedirectResponse
    {
        try {
            $bridge->logout($context->tenant()->id);
        } catch (\RuntimeException $e) {
            // Ura offline: shëno gjithsesi si i shkëputur lokalisht — pa bllokim.
            report($e);
        }

        WhatsAppConnection::updateOrCreate([], [
            'status' => WhatsAppConnection::STATUS_DISCONNECTED,
            'phone_number' => null,
            'last_event_at' => now(),
        ]);

        return back()->with('success', 'WhatsApp u shkëput.');
    }

    /**
     * Ngjarjet e urës (status / mesazh hyrës). Publike si channex/webhook:
     * FAIL-CLOSED — token bosh e fik endpoint-in; token i gabuar 403; dhe
     * tenant-i i payload-it DUHET të përputhet me tenant-in e zgjidhur nga
     * hosti (ResolveTenant) — mospërputhja refuzohet, kurrë fallback.
     */
    public function event(Request $request, WhatsAppMessageImporter $importer, TenantContext $context): Response
    {
        $token = (string) config('services.whatsapp_bridge.token');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response('forbidden', 403);
        }

        $tenant = $context->tenant();
        if (! $tenant || (int) $request->input('tenant_id') !== $tenant->id) {
            return response('tenant mismatch', 422);
        }

        try {
            match ((string) $request->input('type')) {
                'status' => $importer->applyStatus((array) $request->input('payload', [])),
                'message' => $importer->importMessage((array) $request->input('payload', [])),
                'presence' => $importer->applyPresence((array) $request->input('payload', [])),
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);

            return response('error', 500);
        }

        return response('ok', 200);
    }
}
