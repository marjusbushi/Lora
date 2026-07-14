<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lora Control Panel
    |--------------------------------------------------------------------------
    |
    | The control plane is tenantless and belongs to Lora PMS, not to a hotel.
    | Staging keeps its existing host until admin.staging.lorapms.com is wired.
    |
    */
    'control_panel_url' => rtrim((string) env('LORA_CONTROL_PANEL_URL', env('APP_URL', 'http://localhost')), '/'),

    // One-time Control Panel -> hotel-domain sign-in code lifetime. Kept short
    // and capped in code so a deployment misconfiguration cannot make it long-lived.
    'tenant_handoff_ttl_seconds' => (int) env('LORA_TENANT_HANDOFF_TTL_SECONDS', 60),

    'control_panel_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env(
            'LORA_CONTROL_PANEL_HOSTS',
            'admin.lorapms.com,admin.staging.lorapms.com,staging.lorapms.com',
        )),
    ))),

    'dedicated_control_panel_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env(
            'LORA_DEDICATED_CONTROL_PANEL_HOSTS',
            'admin.lorapms.com,admin.staging.lorapms.com',
        )),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Public product website hosts
    |--------------------------------------------------------------------------
    |
    | These hosts render the Lora PMS marketing website instead of a tenant's
    | public booking website. Hotel domains continue through WebsiteController.
    |
    */
    'marketing_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env(
            'LORA_MARKETING_HOSTS',
            'lorapms.com,www.lorapms.com,staging.lorapms.com',
        )),
    ))),
];
