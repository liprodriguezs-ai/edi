# Subscription State Derivation Specification

## Purpose

Split the subscription state into a cheap gate (`is_blocked`, hot path) and a rich narrative (`status`, `trial_ends_at`, the API-owned days-left count per `trial-countdown-utility`, `payment_issue`, `trial_auto_charges`, allowlisted routes only), with trial truth read from Cashier's synced `stripe_status` instead of the local `stripe_trialing` metadata marker.

## Requirements

### Requirement: Cheap gate adds zero hot-path queries

The gate MUST answer `is_blocked` exclusively from `tenant_entitlement`. It MUST NOT read Cashier's `subscriptions` table or resolve the account owner, and it remains the sole input of `EnsureSubscriptionActive`. Its query count MUST never exceed today's for the same request; on the blocked branch it MAY be strictly lower (today's blocked branch runs extra `exists` queries the gate no longer needs).

#### Scenario: Query count never exceeds pre-change on a non-allowlisted request

- GIVEN an authenticated request to a non-allowlisted route
- WHEN the middleware enforces the gate with `DB::listen` capturing queries
- THEN the query count is less than or equal to the pre-change count for the same request
- AND no captured query touches the `subscriptions` table

#### Scenario: Blocked branch runs strictly fewer queries

- GIVEN an authenticated request from a BLOCKED cluster to a non-allowlisted route
- WHEN the gate resolves `is_blocked` with `DB::listen` capturing queries
- THEN it runs strictly fewer queries than today's blocked branch (one entitlement query instead of two or three)

### Requirement: Narrative composes the gate

The narrative MUST be built by composing the gate result plus at most one Cashier subscription read. It MUST NOT recompute `is_blocked` through an independent code path. Its only consumers are the two current callers of the full state — `app/Http/Controllers/AuthController.php:308` and `Modules/Tenant/app/Http/Controllers/TenantProvisioningController.php:65` — neither of which sits on the middleware's hot path (auth routes are allowlisted; tenant provisioning is a super-admin route outside the gate's scope).

#### Scenario: is_blocked is the gate's value

- GIVEN any cluster state
- WHEN both the gate and the narrative are resolved
- THEN the narrative's `is_blocked` is the gate's computed value, not a re-derivation

### Requirement: Trial state derives from Stripe

A cluster whose Cashier subscription has `stripe_status='trialing'` MUST report `status='trial'`, `trial_auto_charges=true`, and `trial_ends_at` equal to the subscription's `trial_ends_at` — NOT the entitlement expiry.

#### Scenario: Stripe trial reports Stripe's date

- GIVEN an active `source=stripe` entitlement and a Cashier row `stripe_status='trialing'` with `trial_ends_at=T`
- WHEN the narrative is resolved
- THEN `status='trial'` AND `trial_ends_at=T` AND `trial_auto_charges=true`

### Requirement: Day-31 conversion reports active even with a perpetual entitlement

Once the subscription is no longer trialing (`stripe_status='active'`), the cluster MUST report `status='active'` regardless of entitlement `expires_at` — including NULL (perpetual), the case where the removed marker previously stuck and kept reporting `trial` while charging.

#### Scenario: Stuck-marker case now reports active

- GIVEN a `source=stripe` entitlement with `expires_at=NULL` AND Cashier `stripe_status='active'`
- WHEN the narrative is resolved
- THEN `status='active'` AND no trial countdown is exposed

### Requirement: Courtesy trial preserved

An active `source=trial` entitlement with NO Cashier subscription row MUST report `status='trial'`, `trial_ends_at` from the entitlement expiry, and `trial_auto_charges=false`.

#### Scenario: Local courtesy trial

- GIVEN only an active `source=trial` entitlement and no Cashier row
- WHEN the narrative is resolved
- THEN `status='trial'` with the entitlement expiry AND `trial_auto_charges=false`

### Requirement: payment_issue derivation

`payment_issue` MUST be true when `stripe_status ∈ {past_due, unpaid}` (primary). When Stripe provides no live payment signal, `tenant.status === PENDIENTE_PAGO` MUST still yield `payment_issue=true` (fallback protecting manual/legacy flows and the E2E-verified recovery screen).

#### Scenario: past_due flags payment_issue

- GIVEN a Cashier row with `stripe_status='past_due'`
- WHEN the narrative is resolved
- THEN `payment_issue=true`

#### Scenario: Stripe-canceled cluster keeps the recovery state (regression pin)

- GIVEN a cluster whose subscription is Stripe-canceled AND whose tenant is still `PENDIENTE_PAGO`
- WHEN the narrative is resolved
- THEN `payment_issue=true` (the payment-recovery screen keeps rendering)

#### Scenario: Manual/legacy fallback without Cashier row

- GIVEN a blocked cluster with prior Stripe entitlements, no Cashier row, tenant `PENDIENTE_PAGO`
- WHEN the narrative is resolved
- THEN `payment_issue=true`

### Requirement: Entitled-branch payment_issue keeps today's behavior

On the ENTITLED branch (cluster with live access), `tenant.status === PENDIENTE_PAGO` MUST yield `payment_issue=true` unconditionally — regardless of what the Cashier row says. The Stripe dunning statuses (`past_due`, `unpaid`) are an ADDITIONAL true-condition, never a replacement: `handleInvoicePaymentFailed` sets `PENDIENTE_PAGO` without writing any subscription status, and under Stripe retry dunning the subscription can stay `active` for the whole retry window, so replacing the tenant-status signal would blank the "update your card" warning for days.

#### Scenario: Dunning with a still-active subscription keeps the warning

- GIVEN an entitled cluster with tenant `PENDIENTE_PAGO` AND a Cashier row still `stripe_status='active'` (retry window)
- WHEN the narrative is resolved
- THEN `payment_issue=true`

### Requirement: Five states survive

The service MUST keep exactly the five states `none`, `trial`, `active`, `expired`, `exempt`. Super admin and lawyer MUST report `exempt` with `is_blocked=false`, short-circuited before any tenant read.

#### Scenario: Super admin is exempt

- GIVEN a user holding the super admin (or lawyer) role
- WHEN state is resolved
- THEN `status='exempt'` AND `is_blocked=false`

### Requirement: Regression floor (measured baselines)

Existing suites MUST NOT regress against baselines measured this session: backend `php artisan test --filter=Billing` ≥ 280 passed, frontend `pnpm vitest run` ≥ 760 tests / 99 files. The SAME backend command MUST be used before and after (`--filter=Billing` matches test names and sweeps ~11 tests outside `tests/Feature/Billing`; never compare against directory-scoped runs).

#### Scenario: Baselines hold

- GIVEN the implemented change
- WHEN both suites run (backend: single process, host-DB overrides)
- THEN backend ≥ 280 passed AND frontend ≥ 760 passed
