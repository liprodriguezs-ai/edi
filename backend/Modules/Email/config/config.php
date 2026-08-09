<?php

return [
    'name' => 'Email',

    /*
    | Base URL for email assets (logo, banner). Must be absolute so images load
    | in mail clients. Defaults to app URL + /email (served from public/email/).
    */
    'assets_url' => env('EMAIL_ASSETS_URL', null),
];
