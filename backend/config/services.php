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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        // Local/dev: a filesystem PATH to the service-account JSON.
        'credentials' => env('FIREBASE_CREDENTIALS'),
        // Managed hosting (Laravel Cloud): the service-account JSON itself, passed
        // as an env var (raw JSON or base64-encoded). Preferred in environments
        // where you cannot ship a secret file. Resolved by FirebasePushChannel.
        'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        // Default subscription plan slug used by the subscription guard.
        'plan_default' => env('PLAN_DEFAULT'),
    ],

    'facebook' => [
        // Meta Pixel id (also used client-side by fbq).
        'pixel_id' => env('FACEBOOK_PIXEL_ID'),
        // Conversions API access token (Events Manager → Settings → Conversions API).
        'capi_access_token' => env('FACEBOOK_CAPI_ACCESS_TOKEN'),
        // Shows dispatched events in Meta's Test Events tool without affecting live data.
        'test_event_code' => env('FACEBOOK_TEST_EVENT_CODE'),
    ],

    'google_analytics' => [
        // GA4 property Measurement ID (also used client-side by gtag).
        'measurement_id' => env('GA_MEASUREMENT_ID'),
        // Measurement Protocol API secret (Admin → Data Streams → Measurement Protocol).
        'api_secret' => env('GA_API_SECRET'),
    ],

];
