# Stripe Trial Billing Specification

## Purpose

The free trial moves inside Stripe: 30 days as a `trialing` subscription with the card on file, auto-charging on day 31. Covers the one-trial-per-cluster lock, trial parameter constraints, webhook activation, expiry safety, the day-31 hard-cut failure policy with its recovery flow, countdown visibility, trial analytics, and legacy trials.

---

## Requirements

### Requirement: One 30-day Stripe trial per cluster

Checkout MUST include a 30-day trial (shared billing config default: 14 → 30) ONLY when the tenant has never had ANY `TenantEntitlement` row. A tenant with any prior entitlement (legacy local trial, cancel+rebuy, prior grant) MUST check out without a trial and be charged immediately.

#### Scenario: Fresh cluster gets the trial

- GIVEN a tenant with zero `TenantEntitlement` rows (past or present)
- WHEN the owner completes Stripe Checkout
- THEN the subscription is `trialing` with `trial_end` ≈ now + 30 days

#### Scenario: Cancel-and-rebuy charges immediately

- GIVEN a tenant with any prior `TenantEntitlement` (e.g. an expired trial)
- WHEN the owner checks out again
- THEN the session carries no trial AND the first charge happens immediately

### Requirement: Trial parameter constraints

The Checkout request MUST NOT ask for a zero-day trial — the billing library's 48-hour floor coerces it into a 2-day trial (`SubscriptionBuilder.php:388`); skipping the trial MUST be done by omitting trial parameters entirely. The request MUST NOT carry `subscription_data.trial_period_days` alongside the library-emitted `trial_end` (Stripe rejects the pair; `array_merge_recursive` at `:411` would send both).

#### Scenario: No-trial checkout carries no trial keys

- GIVEN a tenant not eligible for a trial
- WHEN the Checkout session is created
- THEN the session request contains neither `trial_end` nor `trial_period_days`

### Requirement: Trial checkout activates the cluster with a non-null expiry

A completed trial Checkout (`payment_status='no_payment_required'`, subscription `status='trialing'`) MUST unblock the cluster and grant entitlements with `source=stripe` and a NON-NULL `expires_at` equal to the trial end. If `item.current_period_end` is absent while `trialing`, the system MUST fall back to `subscription.trial_end`. A trial MUST NEVER produce an entitlement with a null expiry — `scopeActive` treats null as perpetual access. (Verification anchors: e2e GATE 1 = cluster unblocks after checkout; GATE 2 = `expires_at = trial_end`.)

#### Scenario: Trial checkout unblocks

- GIVEN a fresh blocked cluster
- WHEN Stripe reports the completed trial session and `trialing` subscription
- THEN the cluster unblocks AND active entitlements exist with `source=stripe` and non-null `expires_at`

#### Scenario: Missing item period end falls back to trial_end

- GIVEN a `trialing` subscription whose items report no `current_period_end`
- WHEN entitlements are granted
- THEN `expires_at = subscription.trial_end` (never NULL)

### Requirement: Day-31 auto-charge extends access

When the first invoice is paid at trial end, entitlement expiry MUST advance to the paid period end (monotonically, never backwards).

#### Scenario: First charge succeeds

- GIVEN a `trialing` cluster reaching `trial_end` with a chargeable card
- WHEN Stripe reports the first invoice as paid
- THEN `expires_at` advances to the new period end AND access continues uninterrupted

### Requirement: Day-31 charge failure is a hard cut

There is NO grace window of access. Trial entitlements expire exactly at `trial_end`; when the first charge fails, the cluster blocks at expiry and the system MUST NOT extend entitlement expiry on invoice payment failure (unlike a failed RENEWAL, which retains access through the already-paid period). The failed-payment webhook marks the tenant pending-payment (`StripeWebhookListener.php:346-374`), which raises the existing `payment_issue` discriminator (`SubscriptionStateService.php:119`). After a failed day-31 charge the cluster is therefore BOTH `is_blocked=true` AND `payment_issue` — a combination the current UI does not yet render distinctly.

#### Scenario: Failed first charge blocks at expiry

- GIVEN a trial ending now whose first charge fails
- WHEN the entitlement expiry passes and the payment-failure webhook arrives
- THEN the cluster is blocked AND the tenant is marked pending-payment
- AND the entitlement `expires_at` was NOT extended

### Requirement: Payment-failure recovery flow

The day-31 block MUST NOT be a dead end. A cluster in the blocked + `payment_issue` state MUST present the owner a recovery flow:

- The message MUST state, in plain Mexican Spanish, that the payment could not be processed — clearly distinguishable from the "never picked a plan" state AND from the "trial ended" state; the three states MUST read differently.
- Copy contract: the message MUST tell the owner what happened (the charge failed), that access is paused, and exactly how to restore it; exact wording is left to implementation (high-quality copy required).
- The screen MUST offer a direct, working action to update the card via the existing Stripe billing portal (already wired at `SelectPlanPage.tsx:92-98`).
- When the payment later succeeds, access MUST be restored through the standard paid-invoice webhook path.

#### Scenario: Blocked owner sees payment failure, not a generic paywall

- GIVEN a cluster blocked by a failed day-31 charge (`is_blocked=true` + `payment_issue`)
- WHEN the owner lands on the paywall/plan screen
- THEN the copy states the payment failed — distinct from no-plan and trial-ended states
- AND a working action opens the Stripe billing portal to update the card

#### Scenario: Recovered payment restores access

- GIVEN the owner updates the card AND Stripe successfully charges the invoice
- WHEN the paid-invoice webhook arrives
- THEN entitlement expiry advances AND the cluster unblocks

### Requirement: Manual grants conflict with a live Stripe entitlement

`grantFree` / `startTrial` invoked against a cluster holding a live `source=stripe` entitlement MUST NOT respond 201 while silently changing nothing (guard A2 no-op today — `EntitlementService.php:110-115`). They MUST respond 409 Conflict identifying the live Stripe subscription as the reason.

#### Scenario: grantFree on a Stripe-active cluster

- GIVEN a cluster with an active `source=stripe` entitlement
- WHEN a super admin calls `grantFree`
- THEN the API returns 409 AND no entitlement rows change

### Requirement: Trial countdown visible to the owner

The owner MUST see the remaining trial days in-app, sourced from `GET /config/subscription` → `subscription.trial_ends_at` (populated by the billing webhook). The countdown MUST NOT be derived from per-request `SubscriptionStateService` detection.

#### Scenario: Trialing cluster shows countdown

- GIVEN a cluster on an active Stripe trial
- WHEN the owner loads the subscription configuration view
- THEN `subscription.trial_ends_at` is non-null AND the UI shows the days remaining until auto-charge

### Requirement: Trial-start analytics fires at checkout completion

The `StartTrial` analytics event MUST fire on successful checkout return — when a trial actually starts — and MUST NOT fire at registration (`useSession.ts:50` today). Registration no longer starts a trial; firing there would count abandonments.

#### Scenario: Abandoned registration emits nothing

- GIVEN an owner who registers and never completes checkout
- WHEN their funnel events are inspected
- THEN no `StartTrial` event was emitted

#### Scenario: Completed trial checkout emits once

- GIVEN an owner returning from a successful trial checkout
- WHEN the return page loads
- THEN exactly one `StartTrial` event is emitted

### Requirement: Legacy local trials preserved

Clusters on the legacy 14-day local trial (`source=trial` entitlements) MUST keep their current behavior and expiry — no backfill, no conversion. The super-admin `MODE_TRIAL` courtesy provisioning path remains a local, no-card trial and inherits the shared 30-day default.

#### Scenario: Legacy cluster unaffected

- GIVEN a cluster midway through a legacy 14-day local trial
- WHEN this change deploys
- THEN its trial status, expiry, and access are unchanged
