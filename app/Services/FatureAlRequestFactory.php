<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** One HTTP policy for every request sent to Fature.al. */
class FatureAlRequestFactory
{
    public function make(?string $token = null, int $timeout = 30): PendingRequest
    {
        $app = trim((string) config('services.fature_al.app_name', 'LoraPMS')) ?: 'LoraPMS';
        $version = trim((string) config('services.fature_al.build_version', 'dev')) ?: 'dev';
        $request = Http::acceptJson()
            ->withUserAgent("{$app}/{$version}")
            ->timeout($timeout)
            ->connectTimeout(5);

        // Solution-provider identity (fature.al, 2026-08): both headers or
        // neither — an unknown/empty pair is rejected with 401 on the spot,
        // while absent headers stay valid until fature.al's enforcement date.
        $clientId = trim((string) config('services.fature_al.client_id', ''));
        $clientSecret = trim((string) config('services.fature_al.client_secret', ''));
        if ($clientId !== '' && $clientSecret !== '') {
            $request = $request->withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-Secret' => $clientSecret,
            ]);
        }

        return filled($token) ? $request->withToken($token) : $request;
    }
}
