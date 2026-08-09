# Tasks: Signup Requires Plan and Card

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~700-950 (backend ~10 files + 1 new + tests; frontend ~4 files + tests; landing ~4 files + docs) |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single delivery (size:exception, no commit/PR) |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Whole change, size-exception (no branches/PRs per delivery strategy) | N/A — uncommitted working tree | `DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact tests/Feature/Billing` | Manual Stripe test-env checkout via `stripe listen` (Phase 8) | Discard working trees (superproject + backend/frontend/landing submodules) |

## Phase 1: Billing Catalog Integrity (must land first)

- [x] 1.1 `Modules/SubscriptionPlan/database/seeders/PlanModuleMatrixSeeder.php:241-242` — stop nulling `stripe_price_id`/`stripe_product_id` on re-seed (D3).
- [x] 1.2 `PlanPricingService.php:431-446` — add `is_purchasable` flag to `buildPlanCatalog` (D4).
- [x] 1.3 `SyncBillingStripeCommand.php` — `Cache::forget(PLAN_CATALOG_CACHE_KEY)` after successful sync (D4).
- [x] 1.4 Create `Modules/SubscriptionPlan/app/Console/Commands/VerifyBillingCatalogCommand.php` — exit 1 if any active plan row has null `stripe_price_id` (D6).
- [x] 1.5 `BillingCheckoutController.php:109-116` — `report($e)` when `reason() === 'no_billable_price'` (D6).
- [x] 1.6 Test: seed-twice preserves Stripe ids + checkout still works; `is_purchasable` true/false + cache bust; `verify-catalog` exit 0/1.

## Phase 2: Backend Trial Enforcement (D1, D2, D7, A2)

- [x] 2.1 `config/billing.php` — `trial_days` 14→30; rewrite stale docblock (`:24-36`).
- [x] 2.2 `AuthController.php` — remove `grantOnboardingTrial()` + call site (`:111`, `:323-361`); create Tenant with `STATUS_PENDIENTE_PAGO`.
- [x] 2.3 `EntitlementService.php` — add `tenantEverHadEntitlement(Tenant): bool` (`withTrashed()->exists()`).
- [x] 2.4 `MultiItemSubscriptionService.php:90` — inject `EntitlementService`; conditional `->trialDays()` only when never-had-entitlement (D1).
- [x] 2.5 `StripeWebhookListener.php:441,704` — add `$fallbackExpiry` param + `subscriptionFallbackExpiry()` helper; wire both callers (`:182`, `:506`) (D2).
- [x] 2.6 `TenantEntitlementController.php` — A2 clobber guard: 409 when grant hits an existing row of a different source (`EntitlementService.php:110-115`).
- [x] 2.7 Comment debt: `AuthController.php:105-111,313-322`, `ExpireManualEntitlementsCommand.php:18,32-36`, `TenantProvisioningService.php:27-28`.
- [x] 2.8 Test: register ⇒ `PENDIENTE_PAGO`, zero entitlements, `is_blocked=true`, `status='none'`; D1 eligibility incl. soft-deleted rows; D2 fallback (± `current_period_end`) never null; A2 → 409.

## Phase 3: Payment-Failure Recovery Signal (D8 backend)

- [x] 3.1 `SubscriptionStateService.php:92-98` — compute `payment_issue` on blocked branch (`everHadEntitlement && PENDIENTE_PAGO && expired SOURCE_STRIPE row`); fix stale comments (`:96-97,101-113`).
- [x] 3.2 Test: D8 matrix — (a) never chose plan → `status='none'`; (b) expired/cancelled → `payment_issue=false`; (c) failed charge → `payment_issue=true`.

## Phase 4: Frontend Routing Enforcement (D5)

- [x] 4.1 `signup-form.tsx:61-71` — ACCOUNT_OWNER always → `/select-plan` (append `?plan=` when valid); drop plan-key precondition.
- [x] 4.2 Test: registration without `?plan=` routes to `/select-plan`, not `/`.

## Phase 5: Frontend Payment Recovery + Catalog UX

- [x] 5.1 `SelectPlanPage.tsx:89-103,195` — add state-(c) payment-failed branch: distinct copy + billing-portal CTA (already wired).
- [x] 5.2 `useBillingQuoter.ts:181-212` — add `no_billable_price` branch (actionable message); consume `is_purchasable` to disable CTA.
- [x] 5.3 Test: `SelectPlanPage` renders (a)/(b)/(c) distinctly; quoter handles `no_billable_price` + `is_purchasable=false`.

## Phase 6: Analytics Correction

- [x] 6.1 `useSession.ts:50` — move `StartTrial` fire from registration to checkout completion (`SelectPlanPage` success view).
- [x] 6.2 Test: abandoned registration fires no event; completed checkout fires exactly one.

## Phase 7: Copy & Legal Sweep (Mexican Spanish, `copywriting` skill)

- [x] 7.1 Draft copy with `copywriting` skill: signup trial disclaimer, payment-failed state, terms clause (30 days, card required, day-31 auto-charge). *(Already delivered as `copy.md`, applied verbatim.)*
- [x] 7.2 `signup-form.tsx:85,99` — apply copy; `:240,244` — real `terminos`/`privacidad` links (drop `href='#'`).
- [x] 7.3 `landing/src/data/site.ts:146,149,308,310,419` + `Pricing.astro:141,283` AND `:630` client `<script>` (hydration reverts static-only edits) — 30 days + card required.
- [x] 7.4 `landing/src/components/BaseHead.astro:85` (JSON-LD) + `pages/terminos.astro:60-61` — replace "no card" claim.
- [ ] 7.5 **DELEGATED — pending `docs-writer` agent.** Not done in this apply run. `/docs` pages `empezar/crear-cuenta-y-plan.mdx:11,39,97`, `cuenta/planes-y-precios.mdx:49,51`, `cuenta/cambiar-de-plan.mdx` per `landing/DOCS-STYLE-GUIDE.md`. Parent orchestrator delegates separately.
- [x] 7.6 Sweep verification: `grep -rn "sin tarjeta\|Sin tarjeta\|14 días\|14 dias" landing/src frontend/src` — zero hits (excluding delegated `/docs`, task 7.5).

## Phase 8: E2E Gates (Stripe test env, repo-unverifiable)

- [ ] 8.1 **OUT OF SCOPE for this run — parent orchestrator runs it.** GATE 1 — `stripe listen` + real trial Checkout: confirm `payment_status` unblocks cluster. Fallback: accept `trialing` explicitly in `handleCheckoutCompleted`.
- [ ] 8.2 **OUT OF SCOPE for this run — parent orchestrator runs it.** GATE 2 — confirm `expires_at = subscription.trial_end`; run `php artisan billing:sync-stripe` first if DB was reset (D2 fallback makes failure non-fatal).

## Post-Review Fixes (found by docs-writer + team-lead while documenting the flow)

- [x] `SelectPlanPage.tsx` `SuccessView` timeout fallback said "Pago recibido" — same D2/§9 problem (a trial never charges). Retitled "Tu prueba está en camino" with matching body; `Reintentar`/`Ir al panel` buttons left literal (docs cite them).
- [x] Removed the legacy `STATUS_ALERTS`/`StatusAlert` component (keyed by raw Stripe statuses `canceled`/`past_due`/`incomplete`/`incomplete_expired`/`unpaid`) — dead code (`SubscriptionStateService` never emits those values) that semantically collided with the canonical §8 `BLOCKED_COPY` states and contradicted the published `/docs/cuenta/acceso-en-pausa.mdx` page.
- [x] **Real bug found and fixed (D7) — corrected per team-lead's architectural review.** A Stripe Checkout trial entitlement is granted with `source=stripe` (not `source=trial` — that constant is reserved for the local card-free paths), so `SubscriptionStateService::activeEntitlementState()` reported `status='active'` for a genuine Stripe trial. This silently broke the trial countdown banner, the "Listo, tu prueba empezó" success copy, and the `StartTrial` analytics firing condition — all three keyed off `status==='trial'`.
  - **First attempt (reverted)**: checked the owner's Cashier `subscriptions` mirror inline in `SubscriptionStateService`. Team-lead flagged this as a real architectural problem: that service runs on every non-allowlisted request via `EnsureSubscriptionActive`, which is deliberately kept cheap (see its own `TODO(infra-cost)`), and the extra query per paid-owner request was an avoidable cost.
  - **Final fix (zero extra queries)**: `StripeWebhookListener::grantEntitlementsFromItems` now stamps a `metadata: {stripe_trialing: true}` marker on the entitlement whenever the subscription's status is `'trialing'` at grant time (wired from both `handleCheckoutCompleted` and `reconcileSubscriptionEntitlements`); `handleInvoicePaymentSucceeded` clears the marker (`metadata: null`) in the SAME update that pushes `expires_at` forward on the day-31 conversion charge. `SubscriptionStateService` gained a new `isTrialLike()` predicate (local `source=trial` OR `source=stripe` + the marker) that reads data already loaded with `$activeEntitlements` — no Cashier/Stripe lookup at all.
  - Tests: `StripeWebhookEntitlementTest::test_checkout_completed_grants_stripe_trialing_marker_which_invoice_payment_succeeded_clears` (full webhook→status E2E, both before and after conversion) + 3 tests in `SubscriptionStateAccessTest.php` (marker present → 'trial'+countdown; marker absent → 'active'; a non-owner member's session also correctly reports 'trial', matching how the pre-existing local `source=trial` branch already behaved for members).
  - **Trial countdown renders in**: `frontend/src/modules/checkoutSubscription/SelectPlanPage.tsx`, `OwnerPlanView` (owner-only) — condition `status === 'trial' && trialEndsAt`, reading `subscription.status` + `subscription.trial_ends_at` from `GET /api/auth/me` (same fields also gate `SuccessView`'s post-checkout trial copy).
  - **Correction accepted**: the trial *countdown on the plan-selection screen* is owner-only (confirmed above), but the `/subscription` screen's trial dates are NOT owner-exclusive — Administrator also sees them there (`manage.subscription`, `RolePermissionTableSeeder.php:334`). This apply run never touched `/subscription` (`SubscriptionPage.tsx`) or made any owner-exclusivity claim about it — only about the plan-selection screen above, which remains correctly owner-gated (`SelectPlanPage()` routes non-owners to `MemberBlockedView` before `OwnerPlanView`/the countdown ever renders).
- [x] **Trial banner said something false for a Stripe trial (D7 follow-up).** `OwnerPlanView`'s trial banner closed with "Contrata un plan para mantener el acceso cuando finalice." — true for a LOCAL trial (no plan, no card), false and confusing for a Stripe trial (already has both, auto-charges on conversion). Fix: `SubscriptionStateService::activeEntitlementState()`'s trial branch now also emits `trial_auto_charges: bool` (true when the trial-like entitlement is `source=stripe` — i.e. carries the `stripe_trialing` marker; false for local `source=trial`) — zero extra cost, same data already loaded. Threaded through `Session.ts` → `SelectPlanPage.tsx`; the banner now branches: `trial_auto_charges===true` states the first charge happens automatically on that date and how to cancel from "Mi suscripción" (no CTA to act now); `false` keeps the original "contrata un plan" wording. Tests: 2 new in `SubscriptionStateAccessTest.php` (Stripe-trial marker → `trial_auto_charges=true`; local `source=trial` → `false`) + 2 new in `SelectPlanPage.test.tsx` (both banner variants render distinctly, each excluding the other's copy).

## Phase 9: Regression Sign-off

- [x] 9.1 `DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact tests/Feature/Billing` (from `backend/`) — **269 passed / 1096 assertions**, one clean serial run (baseline was 245/1044, no regressions). `SubscriptionStateAccessTest.php` run in isolation as an explicit confirmation: **11 passed / 25 assertions**, fully green — the 26-failure run team-lead reported was confirmed invalid (measured mid-edit, 03:07–03:11, while `SubscriptionStateService.php`/`SubscriptionStateAccessTest.php` were still being written).
- [x] 9.2 `./vendor/bin/pint --dirty --format agent` — passed. `pnpm lint` — 0 errors (45 pre-existing warnings, none in touched files). `pnpm exec tsc -b` — clean. `pnpm build` — succeeded. `vitest run` — 751 passed / 98 files.
- [x] 9.3 `php artisan billing:verify-catalog` — command itself verified correct (5 dedicated tests, both exit 0/1 paths). Ran against the local dev DB: **exits 1** because this DB's `plan_module_price` rows were never Stripe-synced (`stripe_price_id` null for all 5 plans × 2 intervals) — a pre-existing local-dev condition, not something this change caused (D3 only stops the seeder from *wiping* an already-set id; it never fabricates one). Running `billing:sync-stripe` to turn this green requires live/test Stripe credentials, which is Phase 8 territory and explicitly out of scope for this run.
