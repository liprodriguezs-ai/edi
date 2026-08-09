<?php

namespace App\Http\Controllers;

use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

/**
 * Stripe webhook entrypoint.
 *
 * Extends Cashier's bundled WebhookController so we inherit:
 *   - the built-in handlers that keep the local `subscriptions` /
 *     `subscription_items` tables in sync (customer.subscription.updated /
 *     .deleted, customer.updated, etc.);
 *   - automatic Stripe signature verification (Cashier attaches
 *     VerifyWebhookSignature middleware whenever config('cashier.webhook.secret')
 *     is set — i.e. when STRIPE_WEBHOOK_SECRET is configured);
 *   - the WebhookReceived event, fired for EVERY event before Cashier processes
 *     it. Our tenant entitlement/status transitions live in a dedicated listener
 *     (App\Listeners\StripeWebhookListener) registered on that event, so this
 *     controller stays a thin pass-through.
 *
 * The route ('stripe/webhook') is registered UNAUTHENTICATED and WITHOUT
 * tenancy: Stripe calls it directly, so there is no Sanctum user and no current
 * tenant. The listener resolves the affected tenant BY ID from event metadata.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * Fail closed: hard-require the webhook signing secret. If
     * STRIPE_WEBHOOK_SECRET is unset, Cashier silently SKIPS signature
     * verification — anyone could then forge events and drive cross-tenant
     * status changes (activate/block any cluster). A 500 here makes a
     * misconfiguration loud instead of a silent security hole.
     */
    public function __construct()
    {
        abort_unless(config('cashier.webhook.secret'), 500, 'Stripe webhook secret not configured.');

        parent::__construct();
    }
}
