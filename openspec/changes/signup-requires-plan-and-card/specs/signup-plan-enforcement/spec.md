# Signup Plan Enforcement Specification

## Purpose

After registration the account owner MUST select a plan and enter a card before the cluster can use the app. No plan ⇒ cluster blocked. Covers tenant birth state, owner routing, paywall allowlist completeness, and abandoned signups.

---

## Requirements

### Requirement: Registration grants no trial and no entitlement

Registration MUST NOT grant any local trial or `TenantEntitlement` (removes the silent 14-day grant at `AuthController.php:111`). The tenant MUST be created with status `pendiente_pago` (`STATUS_PENDIENTE_PAGO`), and its derived subscription state MUST be `status='none'`, `is_blocked=true` (state derives from entitlements only — `SubscriptionStateService.php:77-98`).

#### Scenario: Fresh registration is born blocked

- GIVEN a visitor completes owner registration
- WHEN the tenant is created
- THEN the tenant has zero `TenantEntitlement` rows AND `tenant.status = pendiente_pago`
- AND the session profile reports `subscription.status='none'`, `is_blocked=true`

### Requirement: Owner always lands on plan selection

After successful registration the account owner MUST be routed to `/select-plan`, never the dashboard — with or without a `?plan=` URL parameter (fixes `signup-form.tsx:61-71`). While blocked, subscription-guarded routes MUST redirect to `/select-plan`.

#### Scenario: Registration without a preselected plan

- GIVEN a visitor registers with no `?plan=` parameter
- WHEN registration succeeds
- THEN the browser navigates to `/select-plan`, not `/`

#### Scenario: Blocked owner cannot reach the dashboard

- GIVEN a registered owner with no entitlement
- WHEN they open any subscription-guarded route
- THEN they are redirected to `/select-plan`

### Requirement: Paywall allowlist passes the complete blocked-signup flow

For a blocked cluster the API paywall MUST return 403 `SUBSCRIPTION_INACTIVE` on non-allowlisted routes, and the allowlist MUST pass every request of the blocked-signup flow — the 12 requests verified in exploration V7: `api/auth/*` (register, me, logout), `api/track`, `api/app-config`, `api/billing/*` (catalog, quote, checkout), `api/stripe/*`. Installer `api/onboarding*` routes stay allowlisted; cluster onboarding (`/api/auth/complete-onboarding` + checklist) stays blocked. Tenancy initialization MUST succeed for a blocked fresh owner (checkout carries `initializeTenancy`).

#### Scenario: Blocked owner completes plan selection and checkout

- GIVEN a fresh blocked owner on `/select-plan`
- WHEN they load the catalog, request a quote, and start checkout
- THEN all 12 flow requests succeed with no 403 `SUBSCRIPTION_INACTIVE`

#### Scenario: Everything else stays blocked

- GIVEN the same blocked owner
- WHEN they call any non-allowlisted API route (e.g. cluster onboarding completion)
- THEN the API returns 403 with code `SUBSCRIPTION_INACTIVE`

### Requirement: Abandoned signups persist blocked

A tenant whose owner registers but never completes checkout MUST remain `pendiente_pago`, plan-less, and blocked indefinitely. v1 MUST NOT send reminder emails and MUST NOT run any cleanup/expiry job for abandoned signups.

#### Scenario: Signup abandoned after registration

- GIVEN an owner registered 60 days ago who never checked out
- WHEN the tenant state is inspected
- THEN it is still blocked `pendiente_pago` AND no reminder was sent AND the tenant still exists
