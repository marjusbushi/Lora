<?php

namespace App\Support;

class McpOAuthMetadata
{
    /** @return array<string, mixed> */
    public static function authorizationServer(string $oauthPrefix = 'oauth'): array
    {
        return [
            'issuer' => url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => url($oauthPrefix.'/register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['mcp:use', 'offline_access'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ];
    }

    /** @return array<string, mixed> */
    public static function protectedResource(string $path = ''): array
    {
        return [
            'resource' => url('/'.ltrim($path, '/')),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp:use'],
        ];
    }
}
