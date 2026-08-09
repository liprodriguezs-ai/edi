<?php

return [
    'name' => 'Collections',

    /*
    |--------------------------------------------------------------------------
    | Account-statement totals cache
    |--------------------------------------------------------------------------
    | PaymentController::statement caches the per-unit totals (adeudo / saldo a
    | favor / pagado histórico) + credit balance under the key
    | statement_totals:{tenant_id}:{unit_id}. The StatementTotalsCacheObserver
    | invalidates it on any Charge / CreditBalance write. Set `enabled` to false
    | to always compute live (no caching).
    */
    'statement_cache' => [
        'enabled' => env('COLLECTIONS_STATEMENT_CACHE_ENABLED', true),
        'ttl' => env('COLLECTIONS_STATEMENT_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catch-up generation cap
    |--------------------------------------------------------------------------
    | GenerateMonthlyChargesCommand chains missing periods from each policy's
    | last batch up to this many periods per policy per run; any remainder
    | generates in subsequent runs. Overridable in tests via config([...]).
    | The --from flag lifts this cap for its run (see command PHPDoc).
    */
    'catchup_max_periods' => 12,

    /*
    |--------------------------------------------------------------------------
    | Creation-time backlog budget (CHARGES, not periods)
    |--------------------------------------------------------------------------
    | Creating a periodic policy with a RETROACTIVE valid_from materializes its
    | whole backlog inline — inside an HTTP request, unlike the cron. The depth
    | is therefore bounded by how many CHARGE rows the request may insert
    | (periods × target units), not by a period count alone: 12 periods over 6
    | units is trivial, over 300 units it is 3,600 inserts and a timeout.
    | ChargePolicyService::creationChainCap turns this budget into a period cap
    | (never below 1, never above catchup_max_periods); the daily cron drains
    | whatever is left, contiguously. Overridable in tests via config([...]).
    */
    'creation_max_charges' => 600,
];
