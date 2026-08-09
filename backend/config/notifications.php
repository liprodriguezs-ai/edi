<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    |
    | List of channel drivers to use when dispatching notifications.
    | Supported: "log", "firebase_push", "resend_email", "sms"
    |
    | "log" (in-app) is always active. "firebase_push" is enabled by default so
    | push works out of the box once FIREBASE_CREDENTIALS is set — the channel
    | self-skips (logs a warning) when credentials are absent, so this is safe in
    | environments without Firebase. Override per-environment via the
    | NOTIFICATION_CHANNELS env var (the test suite pins it to "log").
    |
    */

    'channels' => explode(',', env('NOTIFICATION_CHANNELS', 'log,firebase_push')),

];
