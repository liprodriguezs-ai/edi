<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription enforcement
    |--------------------------------------------------------------------------
    |
    | When true (fail-secure default), the EnsureSubscriptionActive middleware
    | turns the cluster's `is_blocked` state into a real server-side boundary:
    | a tenant with no active entitlement receives a 403 on every cluster-data
    | endpoint (the pay-flow + auth routes stay open so the owner can recover).
    |
    | It doubles as an incident kill-switch: set BILLING_ENFORCE_SUBSCRIPTION=false
    | to disable enforcement without a deploy. The automated test suite runs with
    | it disabled (see phpunit.xml) so unrelated fixtures need not carry billing
    | entitlements; the dedicated enforcement test flips it on explicitly.
    |
    */

    'enforce_subscription' => (bool) env('BILLING_ENFORCE_SUBSCRIPTION', true),

    /*
    |--------------------------------------------------------------------------
    | Trial length
    |--------------------------------------------------------------------------
    |
    | Self-registration (AuthController) no longer grants a local trial: a
    | brand-new cluster is born blocked (pendiente_pago, zero entitlements) and
    | the owner must choose a plan + register a card at /select-plan. The trial
    | now lives in the Stripe Checkout session (MultiItemSubscriptionService),
    | which grants exactly ONE trial per cluster (never-had-any-entitlement,
    | tracked withTrashed) and is policed by Stripe's own dunning, not the daily
    | billing:expire-manual-entitlements cron.
    |
    | This is still the single source of truth for the trial LENGTH, shared by:
    |   - MultiItemSubscriptionService::buildCheckout (Stripe trial_end)
    |   - TenantProvisioningService::grantTrial (super-admin no-card courtesy
    |     trial — MODE_TRIAL — which stays local and genuinely card-free)
    |   - EntitlementService::startModuleTrial (per-module internal add-on trial)
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Consultative sales contact
    |--------------------------------------------------------------------------
    |
    | Where a cluster larger than the highest published unit band is sent. The
    | public plan catalog (GET /api/billing/plan-catalog) exposes these under
    | `contact` so both the landing and the app render the same "Contáctanos"
    | card without hardcoding the address on each frontend.
    |
    | `contact_whatsapp` is null until a real number exists; the frontends must
    | treat it as optional and fall back to the email CTA.
    |
    */

    'contact_email' => env('BILLING_CONTACT_EMAIL', 'hola@kivo7.com'),

    'contact_whatsapp' => env('BILLING_CONTACT_WHATSAPP'),

];
