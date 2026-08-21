<?php

return [

    'openai' => [
        'chatgpt_connect_url' => env('CHATGPT_CONNECT_URL', 'https://chatgpt.com/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Beds24 channel manager (OTA sync: Booking.com, Expedia, Airbnb). V1 JSON API.
    'beds24' => [
        'api_key' => env('BEDS24_API_KEY'),
        'base_url' => env('BEDS24_BASE_URL', 'https://api.beds24.com/json'),
        'prop_id' => env('BEDS24_PROP_ID', '337873'),
    ],

    // Channex.io channel manager (OTA sync). v1 REST API; auth = account API key
    // sent as the `user-api-key` header. One account holds many properties, so
    // property_id is the default property for single-tenant calls. base_url
    // defaults to production; set CHANNEX_BASE_URL to the staging sandbox while
    // piloting. See the channex-pilot reference memory for the verified contract.
    'channex' => [
        'api_key' => env('CHANNEX_API_KEY'),
        'base_url' => env('CHANNEX_BASE_URL', 'https://app.channex.io/api/v1'),
        'property_id' => env('CHANNEX_PROPERTY_ID'),
        // Must match Property Settings -> Inventory Days in Channex (100..730).
        'state_length_days' => env('CHANNEX_STATE_LENGTH_DAYS', 500),
        // Shared secret echoed in the inbound booking webhook (Channex has no HMAC
        // signing). Set the same value when registering the webhook + in .env.
        'webhook_secret' => env('CHANNEX_WEBHOOK_SECRET'),
    ],

    // Anthropic (Claude) — alternate AI provider (kept for future). Key via Setting or env.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
    ],

    // Google Gemini — powers the AI Pricing Assistant. Key may be set in the UI
    // (Setting 'ai.gemini_key') or via env (GEMINI_API_KEY / GOOGLE_API_KEY).
    'gemini' => [
        'key' => env('GEMINI_API_KEY', env('GOOGLE_API_KEY')),
        // PINNED id, deliberately (policy flipped 2026-08-21, task #403): the
        // rolling alias hot-swapped us onto 3.7-flash in its LAUNCH WEEK — the
        // historical 503-storm window (three real outage windows on Aug 21) —
        // and silently changed the price. Pinning keeps model changes OUR
        // decision. The old hazard (Google retiring concrete ids — the
        // gemini-2.5-flash 404 of Aug 2026) is covered the other way now: the
        // weekly canary pings this exact id and fails loud, and the fallback
        // below keeps guests answered meanwhile.
        'model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),
        // Modeli rezervë për dritaret e mbingarkesës (task #403): kur primari
        // kthen 5xx OSE ngec (timeout), e njëjta kërkesë provohet MENJËHERË te
        // "lite" (pishinë tjetër kapaciteti, më i lirë per token) dhe biseda i
        // ngjitet deri në fund. I fiksuar për të njëjtën arsye si primari.
        // Bosh = pa rezervë.
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    // Ura lokale e WhatsApp (Node/Baileys, daemon në të njëjtin server).
    // Token bosh = integrimi i FIKUR fare (fail-closed në të dy drejtimet).
    'whatsapp_bridge' => [
        'url' => env('WHATSAPP_BRIDGE_URL', 'http://127.0.0.1:3100'),
        'token' => env('WHATSAPP_BRIDGE_TOKEN', ''),
    ],

    // POK (pokpay.io) card payments for the public booking site. Auth = login
    // (keyId/keySecret) → short-lived Bearer token; amounts in MINOR units (cents).
    // Defaults to STAGING until POK_PRODUCTION=true. See the pok-embedded-contract memory.
    'pok' => [
        'production' => env('POK_PRODUCTION', false),
        'base_url' => env('POK_PRODUCTION', false)
            ? 'https://api.pokpay.io'
            : 'https://api-staging.pokpay.io',
        'merchant_id' => env('POK_MERCHANT_ID'),
        'key_id' => env('POK_KEY_ID'),
        'key_secret' => env('POK_KEY_SECRET'),
    ],

    'fature_al' => [
        // Special partner token required only by POST /register. Tenant API
        // tokens returned by that endpoint remain encrypted per hotel.
        'onboarding_token' => env('FATURE_AL_ONBOARDING_TOKEN'),
        // Live-environment partner token (solution-provider account on
        // fature.al). Used ONLY by production onboarding — invoice issuing
        // stays sandbox-locked in the fiscalization services.
        'onboarding_token_production' => env('FATURE_AL_ONBOARDING_TOKEN_PRODUCTION'),
        'app_name' => env('FATURE_AL_APP_NAME', 'LoraPMS'),
        'build_version' => env('FATURE_AL_BUILD_VERSION', env('APP_VERSION', 'dev')),
        // Application identity (solution-provider layer, 2026-08): the SAME
        // pair for every hotel — it names Lora, not the client. Sent as
        // X-Client-Id / X-Client-Secret on every request, but ONLY when both
        // are set: fature.al rejects unknown ids immediately, so empty or
        // placeholder values must never be sent.
        'client_id' => env('FATURE_AL_CLIENT_ID'),
        'client_secret' => env('FATURE_AL_CLIENT_SECRET'),
    ],

    // Laravel Forge — provisions hotel custom domains on the platform's own
    // server (site per domain + Let's Encrypt). server_ip is what customer
    // DNS must point at; app_root is the shared document root every domain
    // serves (both differ between staging and production). Without a token
    // the panel degrades to clear "manual provisioning" messaging.
    'forge' => [
        'token' => env('FORGE_API_TOKEN'),
        'server_id' => env('FORGE_SERVER_ID'),
        'server_ip' => env('FORGE_SERVER_IP'),
        'app_root' => env('FORGE_APP_ROOT'),
        'site_user' => env('FORGE_SITE_USER', 'lorapms'),
    ],

];
