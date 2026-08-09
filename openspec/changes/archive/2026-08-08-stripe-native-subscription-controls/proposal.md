# Proposal: Stripe-Native Subscription Controls

> Full file:line evidence: approved plan `~/.claude/plans/puedes-implementar-este-plan-sorted-moon.md` (Change A) + Engram `billing/signup-requires-plan-and-card`.

## Intent

The code hand-reconstructs subscription states Stripe already syncs into Cashier's `subscriptions` table. The `tenant_entitlement.metadata['stripe_trialing']` mirror has a confirmed latent bug: its clear (`StripeWebhookListener.php:340-343`) is coupled to the monotonic expiry update — a perpetual entitlement keeps reporting `trial` while charging, and the clear wipes the whole metadata blob. The `['active','trialing']` allow-list is hand-written in 4 sites; the trial days-left formula is duplicated untested in 2 frontend components.

## Scope

### In Scope

- Split `SubscriptionStateService`: **cheap gate** (`is_blocked`, 100% `tenant_entitlement`, zero new hot-path queries; sole input of `EnsureSubscriptionActive`) vs **rich narrative** (gate + one Cashier-row read; consumed only by `AuthController` and `/config/subscription`, both middleware-allowlisted).
- `status='trial'` from `stripe_status==='trialing'` (`onTrial()`); `trial_ends_at` from `subscription.trial_ends_at`, not `furthestExpiry()`.
- Courtesy trial preserved: `source=trial` entitlement without Cashier row → `trial`, entitlement expiry, `trial_auto_charges=false`.
- `payment_issue`: primary `stripe_status ∈ {past_due, unpaid}`; fallback `tenant.status===PENDIENTE_PAGO` when no Cashier row (protects the E2E-verified recovery screen).
- Delete marker write (`:493`), coupled clear (`:340-343`), `isTrialLike()`; keep the monotonic `expires_at` update.
- Shared allow-list on `Stripe\Subscription::STATUS_ACTIVE/STATUS_TRIALING`, promoted from `BillingCheckoutController.php:35`, consumed by all 4 sites; extract `accountOwnerForTenant` to a shared point.
- Frontend: `daysUntil(iso)` in `dateHelper.ts` (keep `Math.round` + its comment) + new test file; consume from `SelectPlanPage` and `UpcomingChargeBanner`.
- Rewrite marker-coupled Billing tests; new cases: courtesy trial, day-31 conversion with perpetual entitlement, `past_due`, gate query-count.

### Out of Scope

- Phases 2/3/4 (Stripe cut-off, portal cancellation, 90% warning) — need Stripe dashboard config / revenue policy.
- `cancel_at_period_end` (false premise: no local column; `onGracePeriod() && canceled()` stays).
- Data migration (stale marker rows go inert). Commits/branches/push/worktrees (HARD constraint).

## Capabilities

> `openspec/specs/` does not exist; capabilities live in change folders.

### New Capabilities

- `subscription-state-derivation`: gate/narrative split; invariant: narrative COMPOSES the gate, never recomputes `is_blocked`; five states kept incl. `exempt`.
- `subscription-status-allowlist`: single SDK-constant allow-list for the 4 sites.
- `trial-countdown-utility`: one tested `daysUntil` helper.

### Modified Capabilities

- `stripe-trial-billing` (unarchived `signup-requires-plan-and-card`): the `stripe_trialing` marker requirement is REMOVED.

## Approach

Compose, don't recompute. Cashier's Billable is `User`, not `Tenant` — owner resolution costs +2 queries, so the gate never touches Cashier; the narrative wraps the gate on allowlisted routes only. Use Cashier helpers and SDK constants, not invented strings.

## Affected Areas

| Area | Impact |
|------|--------|
| `backend/app/Services/SubscriptionStateService.php` | split; drop marker reads |
| `backend/app/Listeners/StripeWebhookListener.php` | drop marker write+clear; shared allow-list |
| `Modules/Configuration/.../ConfigurationController.php` | allow-list (:1216,:1486); extract owner resolver |
| `Modules/SubscriptionPlan/.../BillingCheckoutController.php` | promote constant |
| `frontend/src/shared/utils/dateHelper.ts` (+test), `SelectPlanPage.tsx`, `UpcomingChargeBanner.tsx` | `daysUntil` |
| `backend/tests/Feature/Billing/*` | rewrite + new cases |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Service is the whole-API paywall; an error blocks payers | Critical | tests before code; Billing baseline is judge |
| Base is UNCOMMITTED WIP (23 backend + 12 frontend files) | High | hunk-level discipline; no file-level reverts |
| Stripe-canceled cluster stops reporting `payment_issue` | High | `PENDIENTE_PAGO` fallback + pinning test |
| Worktree `backend/.claude/worktrees/fix+billing-base-scope-menu/` has file copies | Low | never touch; exclude from searches |

## Rollback Plan

Nothing committed: discard this change's hunks — by hunk, not by file (same files carry the `signup-requires-plan-and-card` base). Marker rows need no cleanup.

## Dependencies

- Uncommitted `signup-requires-plan-and-card` work (Engram topics `billing/signup-requires-plan-and-card`, `billing/signup-flow-e2e-findings`).
- Baselines: frontend 99 files / 760 tests; backend `--filter=Billing` declared 269 — re-measure first, SINGLE process, host-DB overrides (`DB_HOST=127.0.0.1 DB_PORT=5440`).

## Success Criteria

- [ ] Gate adds ZERO queries on non-allowlisted authenticated requests (`DB::listen` assert).
- [ ] Stripe trial reports Stripe's `trial_ends_at`; day-31 conversion flips to `active` with a perpetual entitlement (stuck-marker scenario).
- [ ] `past_due`/`unpaid` → `payment_issue`; canceled + `PENDIENTE_PAGO` fallback pinned by test.
- [ ] Courtesy trial reports `trial`, `trial_auto_charges=false`.
- [ ] Zero remaining `metadata['stripe_trialing']` reads/writes (worktree excluded).
- [ ] Billing ≥ 269; frontend ≥ 760 incl. `dateHelper` tests; `tsc -b` + lint clean.
