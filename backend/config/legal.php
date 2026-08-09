<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terms & Conditions Version
    |--------------------------------------------------------------------------
    |
    | The current version string of the Terms & Conditions document. When a user
    | accepts the terms, a terms_acceptance row is recorded for this version. The
    | auth/me payload exposes whether the user has accepted THIS version, so that
    | the frontend can gate the experience when the document changes.
    |
    | Bump this string whenever the legal document changes — users will then show
    | terms_accepted=false until they accept again.
    |
    */

    'terms_version' => env('LEGAL_TERMS_VERSION', '1.0'),

];
