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

    // Platform invoices are due this many days after an automatic billing cycle starts.
    'platform_billing_due_days' => (int) env('LORA_PLATFORM_BILLING_DUE_DAYS', 14),

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

    // Explicit non-public hosts used by infrastructure health checks or an
    // internal load balancer. Tenant domains are loaded from tenant_domains.
    'additional_trusted_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('LORA_ADDITIONAL_TRUSTED_HOSTS', '')),
    ))),

    // Shared local cache keeps TrustHosts off the database hot path. Model
    // changes invalidate it immediately; the TTL covers direct SQL changes.
    'trusted_hosts_cache_seconds' => (int) env('LORA_TRUSTED_HOSTS_CACHE_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Tenant onboarding
    |--------------------------------------------------------------------------
    |
    | Keep the intentionally small currency list in one place so the control
    | panel dropdown and server-side validation can never drift apart.
    |
    */
    'tenant_currencies' => [
        'EUR',
        'ALL',
        'USD',
        'GBP',
        'CHF',
        'TRY',
        'CAD',
        'AUD',
        'SEK',
        'NOK',
    ],

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
