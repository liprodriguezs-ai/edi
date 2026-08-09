---
name: cashier-stripe-api-version
description: Cashier v16.5.3 pins Stripe API 2025-08-27.basil where Subscription.current_period_end was removed (moved to subscription items)
metadata:
  type: project
---

Cashier v16.5.3 (this project) pins the Stripe API version via `Cashier::STRIPE_VERSION = Stripe\Util\ApiVersion::CURRENT`, which in stripe/stripe-php ^17.3 resolves to `2025-08-27.basil`.

**Why it matters:** In the `basil` API, `current_period_end` (and `current_period_start`) were REMOVED from the top-level `Stripe\Subscription` object and now live on each `Stripe\SubscriptionItem` (`$item->current_period_end`). Cashier exposes a helper `$subscription->currentPeriodEnd()` that reads from the items.

**How to apply:** Any code doing `$subscription->asStripeSubscription()->current_period_end` will silently get `null` under this version. Use Cashier's `$subscription->currentPeriodEnd()` (returns `CarbonInterface`) instead. Also note `asStripeSubscription()` does a LIVE `subscriptions->retrieve` network call — never call it on a hot read path (list/GET endpoints) without caching.
