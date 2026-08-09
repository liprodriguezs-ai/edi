<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracking Channels
    |--------------------------------------------------------------------------
    |
    | List of channel drivers TrackingDispatcher uses when no explicit channel
    | list is passed to dispatch(). Supported: "log", "facebook", "google_analytics".
    |
    | "log" is the safe default for local/test environments (no network I/O).
    | Production sets TRACKING_CHANNELS=facebook,google_analytics. Each channel
    | self-skips (logs a warning) when its credentials are absent, so this is
    | safe even before credentials are configured.
    |
    */

    'channels' => explode(',', env('TRACKING_CHANNELS', 'log')),

    /*
    |--------------------------------------------------------------------------
    | Graph API Version
    |--------------------------------------------------------------------------
    |
    | Meta Graph API version used by FacebookConversionsChannel.
    |
    */

    'api_version' => env('FACEBOOK_API_VERSION', 'v20.0'),

];
