<?php

return [

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
    ],

];
