# Delta for Stripe Trial Billing

> Base spec: `openspec/changes/signup-requires-plan-and-card/specs/stripe-trial-billing/spec.md` (unarchived base change; `openspec/specs/` does not exist yet).

## ADDED Requirements

### Requirement: Marker fully absent from the codebase

Zero reads or writes of `metadata['stripe_trialing']` MUST remain in the backend source and test trees (`backend/app`, `backend/Modules`, `backend/tests`), excluding `backend/.claude/worktrees/`.

#### Scenario: Search finds nothing

- GIVEN the implemented change
- WHEN the backend tree (worktrees excluded) is searched for `stripe_trialing`
- THEN zero occurrences remain

## MODIFIED Requirements

### Requirement: Day-31 auto-charge extends access

When the first invoice is paid at trial end, entitlement expiry MUST advance to the paid period end (monotonically, never backwards). The paid-invoice handler MUST NOT read or modify entitlement `metadata` — trial detection now derives from Cashier's `stripe_status`, so there is no marker to clear.
(Previously: the expiry update was coupled to clearing the `stripe_trialing` marker via `'metadata' => null`, wiping the whole metadata blob.)

#### Scenario: First charge succeeds

- GIVEN a `trialing` organization reaching `trial_end` with a chargeable card
- WHEN Stripe reports the first invoice as paid
- THEN `expires_at` advances to the new period end AND access continues uninterrupted

#### Scenario: Metadata untouched on conversion

- GIVEN an entitlement carrying arbitrary `metadata`
- WHEN the day-31 paid-invoice webhook is processed
- THEN `expires_at` advances AND `metadata` is unchanged

## REMOVED Requirements

### Requirement: stripe_trialing metadata marker mirrors the Stripe trial

(Reason: the marker was a local mirror of `subscription.status === 'trialing'` with a confirmed latent bug — its clear was coupled to the monotonic expiry update, so a perpetual entitlement kept reporting `trial` while being charged, and the clear nulled the entire metadata blob.)
(Migration: trial detection moves to Cashier's `stripe_status` (see `subscription-state-derivation`). No data migration — stale marker rows go inert. Marker-coupled Billing tests are rewritten: `SubscriptionStateAccessTest`, `StripeWebhookEntitlementTest`, `SubscriptionEnforcementTest`, `OwnerSubscriptionLifecycleTest`.)
