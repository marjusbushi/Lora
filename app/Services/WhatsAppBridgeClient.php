<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klienti HTTP drejt urës lokale Node/Baileys (daemon në të njëjtin server).
 * FAIL-CLOSED: pa token të konfiguruar, çdo thirrje refuzohet që këtu — dhe
 * kur ura është offline, thirrja hedh RuntimeException me mesazh shqip që
 * kontrolluesit e kthejnë si gabim të qetë (kurrë 500 te useri).
 */
class WhatsAppBridgeClient
{
    public function configured(): bool
    {
        return (string) config('services.whatsapp_bridge.token') !== '';
    }

    /**
     * Nis (ose rikthen) sesionin e tenant-it dhe i jep urës URL-në e ngjarjeve —
     * hosti i vetë hotelit, që ResolveTenant ta zgjidhë si çdo webhook tjetër.
     */
    public function start(int $tenantId, string $eventUrl): array
    {
        return $this->request('post', "/sessions/{$tenantId}/start", ['event_url' => $eventUrl]);
    }

    /** @return array{status:string, qr:?string, phone:?string} */
    public function status(int $tenantId): array
    {
        return $this->request('get', "/sessions/{$tenantId}");
    }

    public function logout(int $tenantId): array
    {
        return $this->request('post', "/sessions/{$tenantId}/logout");
    }

    /** Dërgon tekst te një jid; kthen ['id' => id e mesazhit] për dedup echo. */
    public function send(int $tenantId, string $jid, string $text): array
    {
        return $this->request('post', "/sessions/{$tenantId}/send", ['jid' => $jid, 'text' => $text]);
    }

    /**
     * Treguesi "po shkruan..." te mysafiri (task #368) — best-effort: thirrësi
     * duhet ta kapë dështimin dhe të vazhdojë (urë e vjetër pa endpoint-in,
     * daemon offline — treguesi është zbukurim, jo kusht për dërgimin).
     */
    public function typing(int $tenantId, string $jid, string $state = 'composing'): array
    {
        return $this->request('post', "/sessions/{$tenantId}/typing", ['jid' => $jid, 'state' => $state]);
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Ura e WhatsApp s\'është e konfiguruar në këtë server.');
        }

        $url = rtrim((string) config('services.whatsapp_bridge.url'), '/').$path;

        try {
            $response = Http::withToken((string) config('services.whatsapp_bridge.token'))
                ->timeout(10)
                ->{$method}($url, $payload);
        } catch (\Throwable $e) {
            report($e);

            throw new RuntimeException('Ura e WhatsApp nuk përgjigjet — kontrollo daemon-in në server.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Ura e WhatsApp ktheu gabim ('.$response->status().').');
        }

        return (array) $response->json();
    }
}
