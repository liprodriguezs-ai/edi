<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard Report Cache
    |--------------------------------------------------------------------------
    |
    | Controls server-side caching for the 5 heavy SQL aggregation endpoints
    | used by the financial dashboards (P&L, cashflow, expenses, aging,
    | funding-summary). Keys are tenant-scoped to prevent cross-tenant leaks.
    |
    | DASHBOARD_REPORT_CACHE_ENABLED  — set to false to bypass cache and always
    |   re-run the aggregation queries (useful in development or debugging).
    |
    | DASHBOARD_REPORT_CACHE_TTL  — time-to-live in seconds for each cached
    |   result. Default is 300 (5 minutes). Valid for all 5 endpoints.
    |
    */

    'reports' => [
        'cache_enabled' => env('DASHBOARD_REPORT_CACHE_ENABLED', true),
        'cache_ttl' => (int) env('DASHBOARD_REPORT_CACHE_TTL', 300),
    ],

];
