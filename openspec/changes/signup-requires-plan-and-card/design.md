# Design: Signup Requires Plan and Card

> Session params: mode=auto, store=hybrid, delivery=no-commit/no-branch/no-push, review_budget_lines=800. Baseline: `tests/Feature/Billing` GREEN (245 passed / 1044 assertions) before any edit.

## Technical Approach

Move the trial from a local 14-day `TenantEntitlement` grant into Stripe (30-day `trialing` subscription) and make plan+card mandatory after registration. The receiving machinery is verified working and stays untouched on the happy path: `handleCheckoutCompleted` accepts `no_payment_required` (`StripeWebhookListener.php:133`), `trialing` reconciles like `active` (`:261`), `invoice.payment_succeeded` pushes expiry monotonically (`:324-335`), and the `EnsureSubscriptionActive` allowlist covers the entire blocked-signup flow. Delta: registration creates a blocked tenant, owner always routes to `/select-plan`, checkout conditionally adds `trialDays()`, webhook gains a null-expiry fallback, seeder stops wiping Stripe ids, and the blocked state becomes distinguishable for the payment-failure recovery UX.

Fixed context (justified, not relitigated): trial via `->trialDays(n)` on the `SubscriptionBuilder` before `->checkout()` — Cashier emits `subscription_data.trial_end` (`SubscriptionBuilder.php:381-401`); never `trialDays(0)` (48h floor at `:388` forces a 2-day trial — omit the call instead) and never pass `trial_period_days` in session options (`array_merge_recursive` at `:411` would send both keys). No trial detection in `SubscriptionStateService` — it runs per-request in the middleware (cost TODO at `EnsureSubscriptionActive.php:100-111`); the countdown comes from `GET /config/subscription`, which already serializes `stripe_status` + `trial_ends_at` from Cashier's `subscriptions` table (`ConfigurationController.php:930-931`; Cashier's own WebhookController — the emitter of the `WebhookReceived` our listener consumes — keeps that column synced). Card capture stays on hosted Stripe Checkout; `frontend/package.json` has no Stripe.js.

## Architecture Decisions

### D1 — One-trial-per-cluster lock

**Choice**: `EntitlementService::tenantEverHadEntitlement(Tenant): bool` = `TenantEntitlement::withTrashed()->where('tenant_id', $id)->exists()`. `MultiItemSubscriptionService::buildCheckout` (inject `EntitlementService`) calls `->trialDays(config('billing.trial_days'))` only when false.
**Alternatives**: check only active rows (rejected — cancel+rebuy would re-trial); Stripe-side `customer.trial_used` (rejected — no such primitive, extra API call).
**Rationale**: one indexed `exists()` (unique `(tenant_id, billable_module_id)` gives a tenant_id prefix) — cheap. `withTrashed()` because `reconcileSubscriptionEntitlements` soft-deletes removed-item rows (`:516-521`). Race-safe: `buildCheckout` runs inside the existing per-owner atomic lock (`BillingCheckoutController.php:80-87`) and behind the `already_subscribed` 409 (`:90-95`).

### D2 — Null-expiry fallback (perpetual-access guard)

**Choice**: `grantEntitlementsFromItems` (`:441`) gains a `?Carbon $fallbackExpiry` param: `'expires_at' => $this->itemPeriodEnd($item) ?? $fallbackExpiry`. Both callers compute it via new helper `subscriptionFallbackExpiry(array $subscription): ?Carbon` — when `status === 'trialing'`: `trial_end` (unix→Carbon); if trialing and BOTH sources null: `now()->addDays(config('billing.trial_days'))` + `Log::error`. Non-trialing subscriptions keep today's semantics (null tolerated — money already landed; deletion webhook expires rows).
**Rationale**: `TenantEntitlement::scopeActive` (`:113-119`) treats null `expires_at` as perpetual — a trial item missing `current_period_end` on API `basil` would be free service forever. Last-resort bound = 30 days, never infinity. Callers: `handleCheckoutCompleted:182` (has `$subscription`), `reconcileSubscriptionEntitlements:506` (`$object` IS the subscription).

### D3 — Seeder fix

**Choice**: delete `'stripe_price_id' => null, 'stripe_product_id' => null` from the update array of `upsertPrice` (`Modules/SubscriptionPlan/database/seeders/PlanModuleMatrixSeeder.php:241-242` — NOTE: module path, not `backend/database/seeders/`). Columns are nullable → null on create by default; untouched on update. Seeder keeps owning `unit_amount`, `currency`, `is_active` — idempotent for those.
**Rationale**: `updateOrCreate` applies the second array on EVERY update, so any re-seed (`start.sh:419` runs `db:seed`) wiped synced ids → `resolveStripePriceIds` (`:211 whereNotNull`) → total signup outage.

### D4 — `is_purchasable` in the plan catalog

**Choice**: per-plan boolean in `buildPlanCatalog` (`PlanPricingService.php:431-446`): `! $isContactSales && $planPrices->contains(fn ($p) => $p->stripe_price_id !== null)`.
**vs `is_contact_sales`** (`:427`): contact_sales = NO price rows BY DESIGN (`a_medida`) → sales CTA. `is_purchasable=false` WITH rows = operational fault (unsynced Stripe) → frontend disables the CTA with a "temporalmente no disponible" state instead of a 422 dead end.
**Cache**: same `PLAN_CATALOG_CACHE_KEY`; already busted by the seeder (`:147`); ADD `Cache::forget` to `SyncBillingStripeCommand::runSync` after success; TTL 300s backstop. Simplification: plan-level (any interval synced) — interval-level residual covered by D6's 422 handling.

### D5 — Post-registration routing

**Choice**: `signup-form.tsx:61-71` — ACCOUNT_OWNER always navigates to `/select-plan` (append `?plan=` when `isValidBillingPlanKey`); drop the plan-key precondition. Non-owner branch keeps `navigate('/')` — defensive only: `register()` always assigns ACCOUNT_OWNER (`AuthController:101`); invitations flow through `accept-invitation-form.tsx`, not this form, and are untouched.
**Rationale**: explicit intent beats the implicit `/` → `HomeRedirect` → `SubscriptionGuard` bounce (extra render cycle, depends on `is_blocked` having landed in the session). Guards stay as backstops for deep links; `SelectPlanPage:195` still ejects paid users.

### D6 — Operational guardrail for wiped Stripe ids

**Choice**: new read-only `billing:verify-catalog` command (`Modules/SubscriptionPlan/app/Console/Commands/VerifyBillingCatalogCommand.php`): lists every active `plan_module_price` row of an active+public plan with NULL `stripe_price_id`; exits `self::FAILURE` (1) on any hit — CI/post-seed chainable. Runtime loudness: in `BillingCheckoutController`'s catch (`:109-116`), `report($e)` when `reason() === 'no_billable_price'` (`contact_sales` stays silent — expected path). Frontend: `useBillingQuoter.handleCheckoutError` (`:182-212`) adds a `no_billable_price` branch with actionable copy (currently falls to a generic toast).

### D7 — Legacy `source=trial` compatibility

**Choice**: zero changes to the legacy path. Trial branch of `activeEntitlementState` (`:135-145`) still reports `status='trial'` + `trial_ends_at`; `SelectPlanPage:195` still admits trial owners; `ExpireManualEntitlementsCommand` keeps policing `SOURCE_TRIAL` (`:68-72`); `TenantProvisioningService::grantTrial` (`:163-175`) remains the ONLY producer of `SOURCE_TRIAL` rows and inherits 30d from `config('billing.trial_days')` (14→30). New-flow clusters never create trial rows, so this path only shrinks.

### D8 — Day-31 card failure: HARD CUT + recovery flow (RESOLVED)

**Choice**: no grace. `handleInvoicePaymentFailed` (`StripeWebhookListener.php:359-378`) is UNCHANGED — sets `PENDIENTE_PAGO`, never extends `expires_at`. Entitlements expired at `trial_end`, so access ends when the charge fails.
**Recovery signal (the real decision)**: the blocked branch of `SubscriptionStateService::forTenant` (`:92-98`) currently hardcodes `payment_issue: false`, so states (b) and (c) are indistinguishable. Recompute it there: `payment_issue = $everHadEntitlement && $tenant->status === Tenant::STATUS_PENDIENTE_PAGO && <expired SOURCE_STRIPE row exists>` (one extra `exists()` on the blocked branch only). The matrix:

| State | Signals | Owner CTA |
|---|---|---|
| (a) never chose a plan | `status='none'` (fresh tenant is PENDIENTE_PAGO but has zero rows) | checkout |
| (b) trial/legacy expired, or subscription cancelled | `status='expired'`, `payment_issue=false` (cancellation sets BLOQUEADO via `:417-420`) | fresh checkout |
| (c) charge failed on live subscription | `status='expired'`, `payment_issue=true` (PENDIENTE_PAGO from failed invoice + stripe rows) | Stripe billing portal (`SelectPlanPage.tsx:89-103`, already wired) — Stripe retries recover the sub |

`STATUS_ALERTS`/`OwnerPlanView` branch on `payment_issue` for the (c) variant; copy authored with the `copywriting` skill at implementation. `payment_issue` still NEVER participates in the `is_blocked` decision.

## Data Flow

    register → Tenant(PENDIENTE_PAGO, no entitlement) → /select-plan
      → POST /billing/checkout [owner lock] → eligible? +trialDays(30) → hosted Checkout (card)
      → checkout.session.completed (paid|no_payment_required)
      → grant source=stripe, expires_at = item.current_period_end ?? trial_end ?? now+30d [D2]
      → recompute → ACTIVO … day 31: invoice.payment_succeeded → push expiry (monotonic)
                              | invoice.payment_failed → PENDIENTE_PAGO → blocked (c) → portal

## File Changes

| File | Action | Description |
|---|---|---|
| `backend/app/Http/Controllers/AuthController.php` | Modify | drop `grantOnboardingTrial()` + call (`:111`, `:323-361`); create Tenant with `STATUS_PENDIENTE_PAGO` (verify `status` mass-assignability; else property-assign) |
| `backend/Modules/SubscriptionPlan/app/Services/MultiItemSubscriptionService.php` | Modify | D1: conditional `->trialDays()` in `buildCheckout` (`:90`); inject `EntitlementService` |
| `backend/Modules/SubscriptionPlan/app/Services/EntitlementService.php` | Modify | D1 helper `tenantEverHadEntitlement()` |
| `backend/app/Listeners/StripeWebhookListener.php` | Modify | D2: `$fallbackExpiry` param + `subscriptionFallbackExpiry()` helper (`:441`, `:704`) |
| `backend/app/Services/SubscriptionStateService.php` | Modify | D8: `payment_issue` on blocked branch (`:92-98`) |
| `backend/Modules/SubscriptionPlan/database/seeders/PlanModuleMatrixSeeder.php` | Modify | D3: stop nulling Stripe ids (`:241-242`) |
| `backend/Modules/SubscriptionPlan/app/Services/PlanPricingService.php` | Modify | D4: `is_purchasable` (`:431-446`) |
| `backend/Modules/SubscriptionPlan/app/Console/Commands/SyncBillingStripeCommand.php` | Modify | D4: `Cache::forget` after successful sync |
| `backend/Modules/SubscriptionPlan/app/Console/Commands/VerifyBillingCatalogCommand.php` | Create | D6: exit-1 catalog check |
| `backend/Modules/SubscriptionPlan/app/Http/Controllers/BillingCheckoutController.php` | Modify | D6: `report()` on `no_billable_price` (`:109-116`) |
| `backend/Modules/SubscriptionPlan/app/Http/Controllers/TenantEntitlementController.php` | Modify | A2 silent no-op → 409 (grant returned row whose `source` ≠ requested → clobber guard hit, `EntitlementService.php:110-115`) |
| `backend/config/billing.php` | Modify | `trial_days` 14→30 + docblock rewrite |
| `frontend/src/components/signup-form.tsx` | Modify | D5 redirect; legal links `:240,244` → absolute landing URLs (`https://kivo7.com/terminos`, `/privacidad` — pages exist on landing only); brand copy `:85,99` ("14 días… sin tarjeta") |
| `frontend/src/modules/checkoutSubscription/SelectPlanPage.tsx` | Modify | D8 state (c) in `STATUS_ALERTS`/`OwnerPlanView` |
| `frontend/src/modules/checkoutSubscription/hooks/useBillingQuoter.ts` | Modify | D6 `no_billable_price` branch; D4 `is_purchasable` consumption (+ catalog schema/PlanCard) |
| `frontend/src/shared/auth/useSession.ts` | Modify | move `StartTrial` tracking from registration (`:50`) to checkout completion (`SelectPlanPage` SuccessView) |
| `landing/` (`Pricing.astro` incl. `:630` client script, JSON-LD, `terminos.astro:60`, `/docs`) | Modify | 30 days + card-required copy (script edit mandatory — hydration reverts static-only edits) |

## Interfaces / Contracts

- Catalog plan entry: `+ is_purchasable: boolean` (additive; `is_contact_sales` unchanged).
- `subscription` payload: `payment_issue` now meaningful when `is_blocked=true` (was constant `false`). Additive for consumers; frontend `ISubscriptionState` unchanged in shape.
- New artisan: `billing:verify-catalog` → exit 0/1.
- 422 `no_billable_price` contract unchanged; now reported server-side and handled client-side.

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Feature (backend) | register ⇒ PENDIENTE_PAGO, zero entitlements, `is_blocked`, `status='none'`; D1 eligibility incl. soft-deleted rows; D2 webhook fallback (trialing payload ± `current_period_end` ⇒ `expires_at` never null); D3 seed-twice preserves ids (extend `PlanPricingTest`); D4 `is_purchasable` + cache bust; D8 (a)/(b)/(c) matrix; A2 409; verify-catalog exit codes | extend `tests/Feature/Billing` (baseline 245 must stay green); webhook tests post synthetic `WebhookReceived` payloads as the existing suite does |
| Frontend | D5 redirect; quoter `no_billable_price` branch; SelectPlanPage (a)/(b)/(c) rendering | Vitest + Testing Library |
| E2E (Stripe test env) | GATE 1: real trial checkout `payment_status` unblocks; GATE 2: `expires_at = trial_end` | manual/scripted with `stripe listen`; the two repo-unverifiable Stripe behaviors |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. (New artisan command is read-only DB + exit code.)

## Comment/Doc Debt (must ship with the change)

| Location | Stale claim |
|---|---|
| `AuthController.php:105-111, 313-322` | register trial comment + `grantOnboardingTrial` docblock — deleted with the code |
| `ExpireManualEntitlementsCommand.php:18` | "trial (onboarding 14-day access — any payment_method, incl. card)" — trial now = super-admin provisioning courtesy only, 30d |
| `ExpireManualEntitlementsCommand.php:32-36` | "card cluster whose 14-day TRIAL lapsed… never subscribed" gap narrative |
| `TenantProvisioningService.php:27-28` | note MODE_TRIAL is now the ONLY producer of `SOURCE_TRIAL` |
| `SubscriptionStateService.php:96-97, 101-113` | "Already hard-blocked… moot" + `payment_issue` "NEVER on blocked branch" — contradicted by D8 |
| `config/billing.php:24-36` | "Single source of truth for both self-registration (AuthController) and super-admin provisioning" — self-registration no longer grants locally; now Stripe `trialDays` + provisioning |

## Migration / Rollout

No schema migration. Ops order: deploy → `php artisan billing:verify-catalog` (must pass; run `billing:sync-stripe` if not) → smoke a real trial checkout. Legacy data untouched. Rollback (pre-commit): discard working trees; (post-deploy): restore the one `grantOnboardingTrial()` call site + drop `trialDays()` — live Stripe trials keep working via the standard webhook path.

## Open Questions

- [ ] None blocking. GATE 1/2 (Stripe runtime behaviors unverifiable from the repo) are verification gates for sdd-verify, not design unknowns; D2's fallback makes GATE 2's failure mode non-fatal.
