<?php

$defaultRedirectDomains = env('APP_ENV') === 'production'
    ? 'https://chatgpt.com,https://chat.openai.com'
    : 'https://chatgpt.com,https://chat.openai.com,http://localhost,http://127.0.0.1,http://[::1]';

return [
    'redirect_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => rtrim(trim($domain), '/'),
        explode(',', (string) env('MCP_OAUTH_REDIRECT_DOMAINS', $defaultRedirectDomains)),
    ))),

    'custom_schemes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_OAUTH_CUSTOM_SCHEMES', '')),
    ))),

    // The issuer is intentionally request-origin based. A global issuer would
    // bypass the authoritative per-hotel host used by the consent flow.
    'authorization_server' => null,
];
