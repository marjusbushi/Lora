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

        return filled($token) ? $request->withToken($token) : $request;
    }
}
