# Plan 055: Server-recompute proration and make the preferential swap crash-safe

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/SubscriptionPlan/app/Services/PreferentialSwapService.php Modules/SubscriptionPlan/app/Services/MultiItemSubscriptionService.php Modules/Configuration/app/Http/Controllers/ConfigurationController.php app/Listeners/StripeWebhookListener.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (touches billing; coordinate with plan 051 if both land together)
- **Category**: bug (correctness) + security
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

Two related billing-integrity issues:

1. **Client-controlled proration.** Every self-serve billing mutation (add addon, change
   interval, change plan, preferential swap) accepts a `proration_date` integer from the
   request and forwards it verbatim to Stripe at apply time — it is never recomputed nor
   checked against a server preview. Stripe bounds it to the current period, but within
   that window a caller can pin a `proration_date` that minimizes the immediate prorated
   charge / maximizes downgrade credit — client-controlled money math on a billing path.

2. **Swap can drift from Stripe.** `PreferentialSwapService` runs the Stripe `swap()`
   (network I/O) BEFORE `repointEntitlements`, which is a separate `DB::transaction` with
   NO row lock that also writes `tenant.billing_package_id`. If `repointEntitlements`
   fails after `swap()` succeeds (DB error / worker crash), Stripe is already on the
   preferential prices while local `billing_package_id` + entitlement plan still point at
   the old plan — and the `customer.subscription.updated` reconcile webhook never writes
   `billing_package_id`, so it won't self-heal. Concurrent swaps also race (no lock).

## Current state

- `backend/Modules/SubscriptionPlan/app/Services/PreferentialSwapService.php`:
  - `applyCard` tail (lines 107–113):
    ```php
    $options = $prorationDate !== null ? ['proration_date' => $prorationDate] : [];
    // Stripe network I/O FIRST, outside the transaction.
    $subscription->swap($prices, $options);
    return $this->repointEntitlements($tenant, $toPlan);
    ```
  - `repointEntitlements` (lines 146–188): `DB::transaction` → `TenantEntitlement::query()->where('tenant_id',...)->active()->get()` (NO `lockForUpdate`) → re-grants each → `$tenant->update(['billing_package_id' => $toPlan->id])` (line 183) → `recompute`.
- `proration_date` originates client-side and is passed through:
  - `Modules/Configuration/app/Http/Controllers/ConfigurationController.php` — `addSubscriptionAddon` (≈944 validate, ≈970 forward), `changeSubscriptionInterval` (≈1074/1113), `changeBillingPlan` (≈1344/1359): validates `proration_date` as a bare `integer` and forwards `$validated['proration_date'] ?? null`.
  - `Modules/SubscriptionPlan/app/Services/MultiItemSubscriptionService.php` (≈95–100 addAddon, ≈131–149 changeInterval): forwards to Stripe `addPrice`/`swap`.
  - `PreferentialSwapController` (≈80–95) → `PreferentialSwapService`.
- The preview endpoints already compute the proration server-side (`previewInvoice(...)->subscriptionProrationDate()`); the APPLY path just doesn't reuse it.
- `app/Listeners/StripeWebhookListener.php` `reconcileSubscriptionEntitlements` (fired by `customer.subscription.updated`, ≈446–489) grants/refreshes stripe entitlements but never sets `tenant.billing_package_id`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/SubscriptionPlan/app/Services/PreferentialSwapService.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=PreferentialSwap` and `--filter=Subscription` | all pass |

> Host php, shared `testing` DB, ONE process at a time.

## Scope

**In scope**:
- Stop trusting request `proration_date` on APPLY: recompute server-side (or drop it and
  let Stripe default) in `MultiItemSubscriptionService` + `PreferentialSwapService` apply
  paths. Remove the `proration_date` validation/forwarding from `ConfigurationController`'s
  apply actions (keep it out of the request contract).
- Make the swap crash-safe: EITHER (preferred) have the `customer.subscription.updated`
  reconcile webhook also derive+persist `tenant.billing_package_id` from the mapped plan,
  so a failed local repoint self-heals; AND/OR take a per-tenant `lockForUpdate` at the
  top of `repointEntitlements`.
- Tests for both.

**Out of scope**:
- The PREVIEW endpoints (already correct).
- The Stripe `swap()` call ordering relative to the transaction (keeping I/O outside the
  tx is correct for Octane — do NOT wrap the network call in the transaction).
- Broader billing refactor (see the ConfigurationController god-controller item in the
  index — separate).

## Steps

### Step 1: Recompute (or drop) proration on apply

In each APPLY path, do not accept `proration_date` from the client. Either omit it (let
Stripe compute the default proration at `now`) or, if a specific timestamp is needed,
recompute it server-side immediately before `swap`/`addPrice` using the same preview call
the preview endpoints use. Remove `proration_date` from the three
`ConfigurationController` apply validators and their forwarded arrays.

**Verify**: `grep -rn "proration_date" backend/Modules backend/app` shows it only in
PREVIEW code paths (and the reconcile), not read from request input on apply. `php -l` clean.

### Step 2: Self-heal `billing_package_id` in the reconcile webhook

In `reconcileSubscriptionEntitlements` (the `customer.subscription.updated` handler),
after granting/refreshing stripe entitlements, derive the plan from the mapped
`plan_module_price.plan` (the same mapping used to grant) and persist
`tenant.billing_package_id` if it differs. This makes a failed local repoint converge to
Stripe's truth on the next webhook.

**Verify**: `php -l app/Listeners/StripeWebhookListener.php` clean.

### Step 3: Lock the tenant in `repointEntitlements`

At the top of the `DB::transaction` closure, take a row lock so concurrent swaps
serialize:
```php
Tenant::query()->lockForUpdate()->find($tenant->id);
```
(Keep this INSIDE the transaction and AFTER the Stripe `swap()` — never hold a lock across
the network call.)

**Verify**: `php -l` clean.

### Step 4: Tests

- Proration: assert the apply path ignores a client `proration_date` (e.g. send a bogus
  value; assert the Stripe fake receives the server-recomputed one or none).
- Swap self-heal: simulate `repointEntitlements` failing after `swap()` (or directly fire
  a `customer.subscription.updated` for a tenant whose `billing_package_id` is stale) →
  assert the reconcile sets `billing_package_id` to the mapped plan.
- Regression: `--filter=PreferentialSwap` (the existing 8 tests) stay green.

**Verify**: `php artisan test --filter=PreferentialSwap` and `--filter=Subscription` → all pass.

### Step 5: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] No apply path reads `proration_date` from the request (grep confirms)
- [ ] Reconcile webhook persists `billing_package_id` from the mapped plan
- [ ] `repointEntitlements` takes a per-tenant lock inside the tx (post-Stripe-I/O)
- [ ] New tests for proration-ignore and swap self-heal; existing swap tests green
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Any excerpt above no longer matches (drift).
- Removing request `proration_date` breaks the frontend preview→apply parity assertion in
  an existing test — if the frontend genuinely depends on echoing the preview's date,
  recompute server-side from the SAME preview instead of dropping it, and report.
- The reconcile handler can't map back to a `billing_package_id` (no plan mapping for a
  price) — report; do not guess a plan.

## Maintenance notes

- Keep the rule: Stripe network calls stay OUTSIDE the DB transaction; the lock protects
  only the local re-point.
- Reviewer: verify no lock is held across a Stripe HTTP call (Octane worker starvation).
- The webhook is now the source of truth for `billing_package_id` — future billing writes
  should not fight it.
