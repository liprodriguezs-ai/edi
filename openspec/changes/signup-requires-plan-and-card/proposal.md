# Proposal: Signup Requires Plan and Card

## Intent

Registration grants a silent 14-day LOCAL trial — no plan, no card (`AuthController.php:111` → `grantOnboardingTrial()`), and `signup-form.tsx:61-71` routes most new owners to `/` instead of `/select-plan` — so paying is optional. New rule: after registering, the account owner MUST pick a plan and enter a card; the trial becomes 30 days INSIDE Stripe (`trialing`), auto-charging on day 31. No plan ⇒ cluster blocked.

## Scope

### In Scope

- Remove `grantOnboardingTrial()`; create tenants as `STATUS_PENDIENTE_PAGO`; fresh clusters start blocked (`status='none'`).
- Always route new account owners to `/select-plan` (`signup-form.tsx:61-71`).
- Cashier `trialDays(30)` on Checkout. Trap: never `trialDays(0)` — 48h floor forces a 2-day trial; omit the call to skip a trial.
- One-trial-per-cluster lock: trial only if the tenant never had ANY `TenantEntitlement`; cancel+rebuy charges immediately.
- Fix `PlanModuleMatrixSeeder::upsertPrice()` wiping `stripe_price_id` on every re-seed (risk #1 — without it this change ships a silent total signup outage) + actionable UI for 422 `no_billable_price` (today a dead-end toast).
- Trial countdown sourced from `GET /config/subscription → subscription.trial_ends_at` (Cashier webhook column), NOT from `SubscriptionStateService`.
- `a_medida`: shown, sales CTA, manual super-admin activation; `grantFree` A2 silent no-op → 409.
- Real legal links in signup (`signup-form.tsx:240,244`, today `href='#'`) — legal exposure once cards auto-charge.
- Move `StartTrial` tracking (`useSession.ts:50`) from registration to checkout completion.
- Copy sweep: landing pricing incl. `Pricing.astro:630` client script (hydration reverts it otherwise), JSON-LD, `terminos.astro` ("no card needed" becomes false), `/docs` manual, in-app copy.

### Out of Scope

- In-app card capture — hosted Stripe Checkout stays.
- Price/plan structure changes.
- Data backfill — legacy 14-day local-trial clusters untouched.
- `MODE_TRIAL` super-admin courtesy trial unchanged (no card; inherits 30d from shared config).
- Commits, branches, push — work stays uncommitted for review.

## Capabilities

> `openspec/specs/` does not exist yet — all capabilities are new.

### New Capabilities

- `signup-plan-enforcement`: mandatory plan+card after registration, blocked-cluster rule, owner routing.
- `stripe-trial-billing`: 30-day Stripe trial, day-31 auto-charge, one-trial lock, countdown visibility.
- `billing-catalog-integrity`: seeder idempotency for Stripe ids, `no_billable_price` UX, `a_medida` contact-sales path.
- `public-billing-copy`: landing/SEO/terms/docs/in-app copy, legal links.

### Modified Capabilities

None.

## Approach

Reuse verified machinery: the webhook already unblocks on `trialing` (V1 — `StripeWebhookListener.php:133-135` accepts `no_payment_required`) and the paywall allowlist already passes the entire blocked-signup flow (V7). Delta: remove the local trial grant, conditional `trialDays(30)` in `MultiItemSubscriptionService`, redirect fix, seeder fix, copy sweep.

## Affected Areas

All rows Modified; nothing new or removed.

| Area | Change |
|---|---|
| `backend/app/Http/Controllers/AuthController.php` | drop local trial; `STATUS_PENDIENTE_PAGO` |
| `Modules/SubscriptionPlan/.../MultiItemSubscriptionService.php` | conditional `trialDays(30)` |
| `backend/database/seeders/PlanModuleMatrixSeeder.php` | preserve Stripe ids on re-seed |
| `TenantEntitlementController` / `EntitlementService` | A2 no-op → 409 |
| `backend/config/billing.php` | trial days 14 → 30 |
| `frontend/src/components/signup-form.tsx` | redirect; legal links |
| `frontend/src/modules/checkoutSubscription/*` | countdown source; 422 UX |
| `landing/` pricing, JSON-LD, `terminos.astro`, `/docs` | 30 days + card required |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Trial Checkout `payment_status` ≠ `no_payment_required` → payer stays blocked (unverifiable from repo) | Unknown | e2e GATE 1: assert cluster unblocks after checkout; fallback: accept trialing sessions in `handleCheckoutCompleted` |
| `item.current_period_end` NULL while `trialing` → NULL `expires_at` → PERPETUAL free access (unverifiable) | Unknown | e2e GATE 2: assert `expires_at = trial_end`; fallback: read `subscription.trial_end` in `grantEntitlementsFromItems` |
| Webhook outage: customer paid but stays blocked (local cushion removed) | Med | Stripe auto-redelivery; document manual event replay |
| Day-31 card failure: instant expiry, no grace window | Med | Accepted consciously (locked decision); revisit on churn |

## Rollback Plan

Nothing is committed — rollback = discard working trees (superproject + backend/frontend/landing submodules). If already live: restore the single `grantOnboardingTrial()` call site and drop `trialDays(30)`; existing Stripe trials keep working through the standard webhook path.

## Dependencies

- Stripe test environment + webhook forwarding for both e2e gates.
- `php artisan billing:sync-stripe` after any `migrate:fresh`.

## Success Criteria

- [ ] Plan-less owner lands on `/select-plan`; API returns 403 `SUBSCRIPTION_INACTIVE`.
- [ ] Checkout yields a `trialing` subscription with `trial_end` +30d; webhook unblocks; gates 1–2 pass (`expires_at` never NULL).
- [ ] Re-running `db:seed` preserves `stripe_price_id`; checkout still works.
- [ ] No second trial for tenants with any prior entitlement.
- [ ] Legacy local-trial clusters unaffected; public copy states 30 days + card; legal links resolve.
