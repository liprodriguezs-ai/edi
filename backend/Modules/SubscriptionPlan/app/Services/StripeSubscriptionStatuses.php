<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Services;

use Stripe\Subscription;

/**
 * The ONE shared definition of "what does a Stripe subscription status mean",
 * built on SDK constants — no invented strings. Replaces four hand-written
 * `['active', 'trialing']`-equivalent literals scattered across the billing
 * surface (StripeWebhookListener, ConfigurationController x2,
 * BillingCheckoutController).
 *
 * Three NAMED sets answer two DIFFERENT questions, deliberately kept apart so
 * neither is reused for the other by accident:
 *   - LIVE: "is this organization already paying / may it manage its subscription?"
 *   - DUNNING: "is a charge failing RIGHT NOW?"
 *   - PAYMENT_SIGNAL (LIVE ∪ DUNNING, always derived — never re-listed):
 *     "does Stripe still speak for this subscription's payment state at all?"
 *     A status outside this union (canceled, incomplete, incomplete_expired,
 *     paused) — or the absence of a row — means Stripe no longer has an
 *     opinion, so payment-issue fallbacks must look elsewhere (tenant.status).
 */
final class StripeSubscriptionStatuses
{
    /** Subscription grants access / is manageable by its owner. */
    public const LIVE = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING];

    /** A charge is failing right now. */
    public const DUNNING = [Subscription::STATUS_PAST_DUE, Subscription::STATUS_UNPAID];

    /** Stripe still has an authoritative opinion on this subscription's payment state. */
    public const PAYMENT_SIGNAL = [...self::LIVE, ...self::DUNNING];

    /**
     * Whether Stripe still speaks for this subscription's payment state.
     * Null-safe: no subscription row is treated the same as one outside the set.
     */
    public static function providesPaymentSignal(?string $stripeStatus): bool
    {
        return $stripeStatus !== null && in_array($stripeStatus, self::PAYMENT_SIGNAL, true);
    }
}
