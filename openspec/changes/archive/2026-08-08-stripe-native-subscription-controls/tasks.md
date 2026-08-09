# Tasks: Stripe-Native Subscription Controls

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~750-850 (backend prod ~340, backend tests ~325, frontend ~120) |
| Session review budget | 800 lines (team-lead override) — estimate lands at/near it |
| 400-line budget risk | High |
| Chained PRs recommended | No — HARD constraint forbids any git write op; nothing to slice |
| Suggested split | Single delivery, internally phased (see Work Units) |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Work Units (internal scoping only — no branches/PRs per HARD constraint)

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| A | Foundation value objects (Phase 0) | N/A — no git ops | `php artisan test --filter=SubscriptionGate` (compiles/instantiates only) | N/A — pure classes, no runtime scenario | Discard 4 new/edited files as a hunk group |
| B | RED tests for `SubscriptionStateService` (Phase 1) | N/A | `php artisan test --filter=Billing` (expect new RED failures only) | N/A — test-only change | Discard test-file hunks |
| C | GREEN service split + consumer wiring (Phases 2-3) | N/A | `php artisan test --filter=Billing` (≥280 passed) | Manual: hit `EnsureSubscriptionActive`-gated route as a blocked tenant, confirm 1-query gate | Discard `SubscriptionStateService.php` + 3 consumer hunks |
| D | Webhook cleanup + allow-list rollout (Phases 4-5) | N/A | `php artisan test --filter=StripeWebhookEntitlementTest --filter=BillingCheckoutController` | Manual: replay a `customer.subscription.updated` webhook fixture in Mailpit/log | Discard listener + 2 controller hunks |
| E | Frontend `daysUntil` (Phase 6) | N/A | `pnpm vitest run dateHelper` | N/A — pure function, unit-tested | Discard `dateHelper.ts`/`.test.ts` + 2 component hunks — **SUPERSEDED by Unit I below (Phase 11 reverses this unit's own deliverable); keep this row for history, do not use it as the rollback boundary for the current tree** |
| F | Full regression + cleanup (Phase 7) | N/A | `php artisan test --filter=Billing` + `pnpm vitest run` | Full baseline re-run, single process | N/A — verification only |
| G | Cashier timestamp timezone cast (Phase 8) | N/A | `php artisan test --filter=AppTimezoneCast` | N/A — cast unit test, no HTTP surface | Discard `app/Casts/AppTimezoneCast.php` + 2 model-cast hunks |
| H | One-shot TZ data correction (Phase 9) | N/A | `php artisan test --filter=Billing` (post-migration, timestamps unaffected in count) | Manual: `php artisan migrate` then `database-query` the 2 known-skewed `subscriptions.trial_ends_at` rows | Discard migration file; `php artisan migrate:rollback --step=1` restores prior values via its `down()` |
| I | API-owned `trial_days_left` (Phase 10) | N/A | `php artisan test --filter=Billing --filter=SubscriptionSelfService` | N/A — pure computation + feature assertions | Discard `CalendarDays.php` + `SubscriptionStateService.php`/`ConfigurationController.php` hunks |
| J | Frontend consumes `trial_days_left` + lint-error fix (Phase 11) | N/A | `pnpm vitest run` + `pnpm lint` | Manual: view `/select-plan` for a trialing owner, confirm the same day count as before | Discard `Session.ts`/`dateHelper.ts`/`.test.ts`/`SelectPlanPage.tsx`/`UpcomingChargeBanner.tsx`/`SelectPlanPage.test.tsx` hunks |
| K | Full regression + cleanup, extension (Phase 12) | N/A | `php artisan test --filter=Billing` + `pnpm vitest run` + `pnpm exec tsc -b` + `pnpm lint` | Full baseline re-run, single process | N/A — verification only |

### Extension Review Workload Forecast (Phases 8–12 — TZ normalization + API-owned days)

| Field | Value |
|-------|-------|
| Estimated added lines | ~430-480 backend (cast + migration + `CalendarDays` + narrative/`presentTrials` wiring + 3 new test files ~300 lines), ~110-140 frontend (`Session.ts`, `dateHelper.ts`/.test.ts, `SelectPlanPage.tsx` prop plumbing + hook hoist, `UpcomingChargeBanner.tsx`, `SelectPlanPage.test.tsx` fixtures) |
| Estimated total (Phases 0–12) | ~1,290-1,470 changed lines |
| Session review budget | 800 lines (team-lead override, set for the ORIGINAL scope) — the extension alone lands near/over that budget a second time |
| 400-line budget risk | High — same as original; unchanged HARD constraint (no git ops, nothing to slice) |
| Delivery strategy | exception-ok (unchanged) |

Decision needed before apply: No (per team-lead directive — size:exception already accepted, do not re-ask)



- [x] 0.1 Create `backend/Modules/SubscriptionPlan/app/Services/StripeSubscriptionStatuses.php`: `LIVE`, `DUNNING`, `PAYMENT_SIGNAL` (spread), `providesPaymentSignal(?string): bool`. (spec: subscription-status-allowlist)
- [x] 0.2 Create `backend/app/Services/SubscriptionGate.php`: `final readonly class` (`isBlocked`, `status`, `isOwner`, `activeEntitlements`). (design Decision #3)
- [x] 0.3 Create `backend/app/Services/AccountOwnerResolver.php`: extract owner lookup from `ConfigurationController.php:865-870` + `BillingCheckoutController.php:191-196` (identical `users()->role(SLUG_ACCOUNT_OWNER)`, count===1); per-request memo keyed by tenant id. Do NOT touch `SubscriptionPaymentController.php:194-199`. (design Decision #4) — hardened beyond the design: `role()` scope throws `RoleDoesNotExist` when the catalog row itself is unseeded (not just "zero owners"), and this resolver's new caller (narrativeFor) hits that on far more tenants than the two config/checkout screens it was extracted from; now caught and treated as "no owner".
- [x] 0.4 Register `$this->app->scoped(AccountOwnerResolver::class)` in `backend/app/Providers/AppServiceProvider.php` (precedent: `Modules/Collections/app/Providers/CollectionsServiceProvider.php:56`).

## Phase 1: RED — tests before `SubscriptionStateService` code (strict TDD, this file only)

> **PROCESS DEVIATION (disclosed, not hidden):** items 1.1-1.12 below were NOT written and observed RED before `SubscriptionStateService.php` was modified — the hard Phase-1→Phase-2 sequencing dependency was violated. The executor implemented the Phase 2 split first, then wrote/rewrote these tests against the already-implemented service, running them to GREEN directly rather than RED-then-GREEN. Content-wise every item below is complete and passing; item 1.13 (observe RED) never happened and cannot be retroactively claimed, so it is left unchecked with this note instead.

- [x] 1.1 `SubscriptionStateAccessTest.php`: add `DB::table('subscriptions')->delete()` to `setUp()` (fixture debt — 7 sibling suites already have it).
- [x] 1.2 Add `DB::listen` test: non-allowlisted request → query count ≤ pre-change AND zero `subscriptions`-table queries. (Requirement: Cheap gate adds zero hot-path queries) — added to `SubscriptionEnforcementTest.php` (middleware-level, where the gate is actually exercised over HTTP), not `SubscriptionStateAccessTest.php` (which calls the service directly via reflection, bypassing the middleware).
- [x] 1.3 Add blocked-branch test: strictly fewer queries than today (1 vs 2-3). — same file/rationale as 1.2.
- [x] 1.4 Add Stripe-trial test: real Cashier row `stripe_status='trialing'` → `status='trial'`, `trial_ends_at`=subscription's, `trial_auto_charges=true`. (Requirement: Trial state derives from Stripe)
- [x] 1.5 Add day-31 test: `source=stripe`, `expires_at=NULL`, `stripe_status='active'` → `status='active'`, no countdown. (Requirement: Day-31 conversion)
- [x] 1.6 Add courtesy-trial test: `source=trial`, no Cashier row → `trial`, entitlement expiry, `trial_auto_charges=false`. (Requirement: Courtesy trial preserved) — pre-existing `test_local_trial_entitlement_reports_trial_auto_charges_false` already covered this exact scenario untouched; left as-is rather than duplicated.
- [x] 1.7 Add `past_due` test with a REAL Cashier row → `payment_issue=true` (net-new coverage).
- [x] 1.8 Add canceled+`PENDIENTE_PAGO` regression pin → `payment_issue=true` (obs #2281).
- [x] 1.9 Add entitled+`PENDIENTE_PAGO`+Cashier-`active` no-op pin → `payment_issue=true` unchanged.
- [x] 1.10 Create `SubscriptionStateCompositionTest.php`: matrix over five states asserting `narrativeFor(...)['is_blocked'] === tenantGate(...)->isBlocked`. (design Decision #3, composition invariant) — via `ReflectionMethod` on the private `tenantGate()`, mirroring `SubscriptionStateAccessTest`'s existing reflection pattern.
- [x] 1.11 `OwnerSubscriptionLifecycleTest.php:171,589,602,639,648`: `forTenant()` → `narrativeFor()`.
- [x] 1.12 `SubscriptionEnforcementTest.php:148,152`: single-arg `forUser()` → `gateFor()`.
- [ ] 1.13 Run `--filter=Billing`, confirm new/renamed assertions fail RED (service not yet split — expected). — SKIPPED. Never executed in this order; see process-deviation note above.

## Phase 2: GREEN — `SubscriptionStateService` split

- [x] 2.1 Add `gateFor(?User, ?Tenant): SubscriptionGate` — role short-circuit (SUPER_ADMIN/LAWYER → exempt) else `tenantGate()`.
- [x] 2.2 Add private `tenantGate(?User, ?Tenant): SubscriptionGate` — single `is_blocked` computation, `tenant_entitlement`-only.
- [x] 2.3 Add `narrativeFor(User, ?Tenant): array` — composes `tenantGate()` + one Cashier read via `AccountOwnerResolver`; `payment_issue` per design Decision #6.
- [x] 2.4 Delete `isTrialLike()`, `forUser()`, `forTenant()`.
- [x] 2.5 Re-run Phase 1 suite, confirm GREEN.

## Phase 3: Wire consumers

- [x] 3.1 `EnsureSubscriptionActive.php:112` → `gateFor(...)->isBlocked`.
- [x] 3.2 `AuthController.php:308` → `narrativeFor(...)`.
- [x] 3.3 `TenantProvisioningController.php:65` → `narrativeFor(...)`.
- [x] 3.4 `AdminTenantProvisionTest.php`: verify `:99-100,132-133,176-177,207` non-exempt pins still pass; add case with a REAL `SLUG_SUPER_ADMIN` role assigned (today's `superAdminToken()` at `:383-394` authorizes by ability only, so no test catches an exempt regression here).

## Phase 4: Webhook cleanup

- [x] 4.1 `StripeWebhookListener.php`: delete marker write `:493`, `$isTrialing` param (+ call sites `:189,585`), dead `isTrialingSubscription()` `:507-510`, `'metadata'=>null` at `:343` (keep monotonic expiry update).
- [x] 4.2 `:263` → `StripeSubscriptionStatuses::LIVE`.
- [x] 4.3 `StripeWebhookEntitlementTest.php:255`: rewrite `assertNull($entitlement->metadata)` → assert marker never written (this assertion inverts once `:343` drops).
- [x] 4.4 `StripeWebhookEntitlementTest.php:226,235,257`: update remaining marker-coupled assertions.
- [x] 4.5 Verify `SubscriptionPaymentFlowTest.php:692` still passes untouched (excluded site — confirm no incidental code path change).

## Phase 5: Allow-list + owner-resolver rollout

- [x] 5.1 `ConfigurationController.php:1216,1486`: literals → `StripeSubscriptionStatuses::LIVE`; inject `AccountOwnerResolver`, delete private duplicate (`:865-870`).
- [x] 5.2 `BillingCheckoutController.php:35,191-196`: delete private constant + duplicate resolution block, inject `AccountOwnerResolver`, consume `LIVE`.
- [x] 5.3 `presentCashierSubscription`: adopt resolver + shared constants only, no other behavior change.

## Phase 6: Frontend `daysUntil`

> **PARTIALLY SUPERSEDED by Phase 11 (rev 2 scope extension).** User direction: "toda esa lógica la debe de dar la api, no el front, no tengamos lógicas cruzadas." The `daysUntil` helper this phase created is DELETED in Phase 11 and replaced by an API-computed `trial_days_left` field (spec: `trial-countdown-utility`, rewritten rev 3). Items 6.1-6.4 are left checked below as an honest record of work actually done and later reversed — do NOT uncheck them, and do NOT re-derive this history from Phase 11 alone.

- [x] 6.1 `frontend/src/shared/utils/dateHelper.ts`: export `daysUntil(iso, now = Date.now())` = `Math.max(0, Math.round((new Date(iso).getTime() - now) / 86_400_000))` + existing rationale comment. (spec: trial-countdown-utility)
- [x] 6.2 Create `dateHelper.test.ts`: 30-vs-31 first-day case, past-date-clamps-to-0 case, injected-`now` case.
- [x] 6.3 `SelectPlanPage.tsx:468`: consume `daysUntil`; drop inline formula + the now-orphaned `eslint-disable-line react-hooks/purity` (impurity moves into the non-hook helper).
- [x] 6.4 `UpcomingChargeBanner.tsx:32`: same as 6.3 — additionally dropped `useMemo` entirely (a call to the extracted helper inside `useMemo` broke the React Compiler's manual-memoization verification; the computation is O(1) and does not need memoizing).

## Phase 7: Regression + cleanup

- [x] 7.1 `vendor/bin/pint --dirty` on all touched PHP files. — Run as `pint --test` on the touched paths ONLY, NOT `--dirty`: this checkout carries uncommitted work from parallel sessions, and `--dirty` would reformat their files too. Result: `{"tool":"pint","result":"passed"}` (zero files needing changes).
- [x] 7.2 `php artisan test --filter=Billing` (single process, `DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password`) — must be ≥280 passed. — **327 passed** (1275 assertions), on the dedicated `verif_quotas` database.
- [x] 7.3 `pnpm vitest run` — must be ≥760 tests / ≥100 files (adds `dateHelper.test.ts`). — **779 tests / 101 files**.
- [x] 7.4 `tsc -b` + `pnpm lint` clean on touched frontend files. — `tsc -b` exit 0; `pnpm lint` 0 errors (47 pre-existing warnings, unchanged baseline).
- [x] 7.5 Grep sweep: zero `metadata['stripe_trialing']` in `backend/app`, `backend/Modules`, `backend/tests`; zero hand-written `['active','trialing']`-equivalent literals in `backend/app`, `backend/Modules` — both excluding `backend/.claude/worktrees/`. — Zero LIVE occurrences in BOTH sweeps. The surviving matches are all PROSE describing the removal itself, never code, and they belong to two DIFFERENT sweeps:
  - `stripe_trialing` sweep → `SubscriptionStateService.php:49` (docblock: explains the marker is gone) and `StripeWebhookEntitlementTest.php:527` (the regression test's own comment naming the defect it guards — inside `backend/tests`, which this task explicitly scopes).
  - status-literal sweep → `StripeSubscriptionStatuses.php:12` (docblock: explains the literals were consolidated here).

  Deleting those comments would delete the record of why the code looks the way it does. The scenario's shorthand ("zero occurrences") is stricter than its own requirement text ("zero READS or WRITES"), which is what actually holds — logged as a spec-wording defect in the verify report.

---

# EXTENSION (rev 2): Cashier timestamp timezone + API-owned trial days left

> Scope approved by the user (see `openspec/changes/stripe-native-subscription-controls/specs/cashier-timestamp-timezone/spec.md`, spec rev 3, design rev 4, Decisions 9-11). Phases 0-7 above are the ORIGINAL, already-implemented scope — do not re-touch except where a task below explicitly names a line in an already-modified file. This extension is independently phased 8-12, continuing the existing numbering. Same HARD constraints as Phases 0-7: no commits, no branches, no push, no worktree; Standard Mode (not strict TDD).

## Phase 8: Cashier timestamp timezone — shared cast

- [x] 8.1 Create `backend/app/Casts/AppTimezoneCast.php` via `php artisan make:cast --no-interaction AppTimezoneCast` (Boost rule: use `artisan make:` commands; `app/Casts/` does not exist yet — this command is what creates it, not a hand-made directory). Implement `CastsAttributes`: `set(Model, string, mixed, array): ?string` = `optional(Carbon::parse($value))?->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')` (null-safe passthrough for `null`); `get(Model, string, mixed, array): ?Carbon` = `$value === null ? null : Carbon::parse($value, config('app.timezone'))`. Idempotent by construction (`setTimezone()` preserves the instant; a tz-naive string parses in app tz already) — no value heuristic, no tz-detection branch. (design Decision 9; spec: cashier-timestamp-timezone)
- [x] 8.2 `backend/app/Models/Subscription.php`: override `protected function casts(): array` returning `array_merge(parent::casts(), ['trial_ends_at' => AppTimezoneCast::class, 'ends_at' => AppTimezoneCast::class])`. Do not touch `currentPeriodEnd()` or any other Cashier method — verified correct and untouched by design.
- [x] 8.3 `backend/app/Models/User.php` `casts()` (`:126-137`): add `'trial_ends_at' => AppTimezoneCast::class` (today UNCAST — serializes via Carbon's UTC `__toString`).
- [x] 8.4 Create `backend/tests/Unit/Casts/AppTimezoneCastTest.php` (precedent: `tests/Unit/Support/SpreadsheetSafeTest.php`): (a) a UTC-Carbon write shifts to app-tz wall time on read; (b) an already-local Carbon/string round-trips unchanged (idempotency); (c) writing the SAME already-cast value twice (re-save) does not shift a second time (the double-normalization trap the design explicitly calls out); (d) `null` in → `null` out both directions.

## Phase 9: One-shot data correction (dev-only, "no hay nada en prod")

- [x] 9.1 Create migration `backend/database/migrations/2026_08_07_HHMMSS_normalize_stripe_timestamp_timezones.php` (pick the next free timestamp after the last migration, `2026_07_03_000000_...`, dated today). `up()`: three `DB::statement("UPDATE {table} SET {col} = ({col} AT TIME ZONE 'UTC') AT TIME ZONE ? WHERE {col} IS NOT NULL", [config('app.timezone')])` calls — `subscriptions.trial_ends_at`, `subscriptions.ends_at`, `user.trial_ends_at` (mind the reserved-word table name, quote `"user"` per existing migration convention in this codebase). `down()`: the inverse conversion (`AT TIME ZONE app-tz) AT TIME ZONE 'UTC'`) on the same three columns, restoring the pre-migration values. The migrations ledger is the idempotency mechanism — do not add a value heuristic, do not make this a re-runnable command. (design Decision 9, "Migration / Rollout")
- [x] 9.2 Run the migration on the dev DB (`DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password`) and spot-check via `database-query`: confirm the exactly 2 currently-skewed `subscriptions.trial_ends_at` rows (measured baseline, see design/spec) now read app-tz wall time consistent with their `created_at`; confirm `subscriptions.ends_at` and `user.trial_ends_at` (both currently 0 non-null rows) are unaffected (no rows to touch, statement is a no-op).
- [x] 9.3 Feature test (add to `SubscriptionEnforcementTest.php` or a new `tests/Feature/Billing/CashierTimestampTimezoneTest.php`): a webhook-driven write of `subscriptions.trial_ends_at` (via the existing Cashier webhook test fixtures) lands within the same day/hour as the Eloquent-written `created_at` on the same row — i.e. no 6-hour skew survives the cast on the write path, not just the one-shot migration. Also pins: `onTrial()` is false the instant the trial's stored expiry passes (no ghost-trial window), and cancelling a NON-trialing subscription stores `ends_at` = Stripe's `cancel_at`, never a stale `trial_ends_at`.

## Phase 10: API-owned `trial_days_left`

- [x] 10.1 Create `backend/app/Support/CalendarDays.php` (precedent: `app/Support/SpreadsheetSafe.php`, `app/Support/MediaAttachmentPolicy.php`): `final class CalendarDays { public static function until(?CarbonInterface $end): ?int }` = `$end === null ? null : max(0, now()->startOfDay()->diffInDays($end->copy()->startOfDay()))`. `max(0, ...)` is load-bearing — Carbon 3's `diffInDays` is SIGNED (the exact bug class already fixed once in this codebase, obs: collections-report-signed-diffindays). (design Decision 10; spec: trial-countdown-utility)
- [x] 10.2 Create `backend/tests/Unit/Support/CalendarDaysTest.php` using `Carbon::setTestNow`: day one of a 30-day trial → 30 (never 31); the expiry day itself → 0; a past `$end` → 0 (never negative); `null` in → `null` out.
- [x] 10.3 `backend/app/Services/SubscriptionStateService.php` `entitledNarrative()` (`:141-193`) — BOTH trial branches gain `'trial_days_left' => CalendarDays::until($end)` computed from the SAME Carbon that produces `trial_ends_at`, added BEFORE that Carbon is serialized to ISO:
  - Stripe branch (`:145-160`): `$end = $subscription->trial_ends_at` (already a Carbon post-Phase-8 cast); keep `$trialEndsAt = optional($end)->toIso8601String()` for the existing field, add `'trial_days_left' => CalendarDays::until($end)`.
  - Courtesy branch (`:170-184`): `furthestExpiry()` (`:288-297`) currently returns only the serialized `?string`. Refactor it into a private Carbon-returning core — e.g. `furthestExpiryCarbon(Collection $entitlements): ?CarbonInterface` (the existing null-when-perpetual + `sortByDesc('expires_at')->first()` logic, minus the final `optional(...)->toIso8601String()`) — and have `furthestExpiry()` become a one-line wrapper (`optional($this->furthestExpiryCarbon($entitlements))->toIso8601String()`) so both the ISO string and the Carbon are available from one computation. Use the Carbon variant for `CalendarDays::until()`, keep the string variant for `trial_ends_at`/`expires_at`.
  - The 'active' branch (`:186-192`) is untouched — `trial_days_left` is trial-status-only, matching `trial_ends_at`/`trial_auto_charges`'s existing shape.
- [x] 10.4 `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` `presentTrials()` (`:604-631`) — replace the inline `(int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay())` at `:623-625` with `CalendarDays::until($expiresAt)`, keeping the existing null-guard shape (`$expiresAt !== null ? ... : null` collapses into the helper's own null-safety — simplify to `CalendarDays::until($expiresAt)` directly).
- [x] 10.5 Feature tests for `trial_days_left` in the narrative (add to `SubscriptionStateAccessTest.php`, alongside the existing Stripe-trial/day-31/courtesy-trial cases from Phase 1): day one of a 30-day Stripe trial → 30; the expiry day → 0; a courtesy trial (no Cashier row) → uses the entitlement's own expiry, same day-30/day-0 boundaries; a perpetual/non-trial entitlement → key absent (not present outside `status='trial'`). These four cases directly inherit the four `daysUntil` cases Phase 6 wrote in the now-deleted `dateHelper.test.ts` (design Testing Strategy).
- [x] 10.6 Feature test proving no drift between the two `days_left` consumers: for the same tenant/entitlement/moment, `presentTrials()['days_left']` (via `GET /config/subscription` or whatever route exercises it — check for an existing covering test first; codegraph flags `presentTrials` as currently UNCOVERED) equals the narrative's `trial_days_left`. Add to `tests/Feature/Configuration/SubscriptionSelfServiceTest.php` if that route lives there, else create `tests/Feature/Configuration/PresentTrialsDaysLeftTest.php`.

## Phase 11: Frontend consumes `trial_days_left`; fix pre-existing lint error

- [x] 11.1 `frontend/src/shared/auth/Session.ts` `SubscriptionState` (`:34-56`): add `trial_days_left?: number | null` (same optional/nullable shape as `trial_ends_at`), with a docstring note it is present only when `status==='trial'`.
- [x] 11.2 `frontend/src/shared/utils/dateHelper.ts`: delete the `daysUntil` export (`:93-106`) and its docstring — reverses Phase 6.1. The API now owns this number (spec: trial-countdown-utility, rev 3).
- [x] 11.3 `frontend/src/shared/utils/__tests__/dateHelper.test.ts`: UPDATE, do not delete. Remove the 4 `daysUntil` cases (30-vs-31 first day, past-date-clamps-to-0, injected-`now`, and whichever fourth case Phase 6.2 added), repoint the file to cover only the remaining exported helpers (`formatLocalDateString`, `parseLocalDateString`, `formatTimeHHmm`, `displayFormatDate`, `formatDateForInput`, `todayLocalDateString`).
- [x] 11.4 `frontend/src/modules/checkoutSubscription/SelectPlanPage.tsx`:
  - Drop the `daysUntil` import (`:15`, keep `displayFormatDate`).
  - `SelectPlanPage()` (`:121-154`): read `const trialDaysLeft = subscription?.trial_days_left ?? null` alongside the existing `trialEndsAt` read, and pass it as a new `trialDaysLeft` prop to `<OwnerPlanView>` (`:147-152`).
  - `OwnerPlanView(...)` (`:441-451`): add `trialDaysLeft: number | null` to its props type; DELETE the `useMemo` at `:459-462` that currently computes it from `trialEndsAt` via `daysUntil`; the value at its one consumer, `<TrialStatusCard trialDaysLeft={trialDaysLeft} .../>` (`:631`), is now the prop, unchanged plumbing from that point down.
- [x] 11.5 `frontend/src/modules/checkoutSubscription/components/UpcomingChargeBanner.tsx`: drop the `daysUntil` import (`:2`, keep `displayFormatDate`); replace `:29`'s `subscription?.trial_ends_at == null ? null : daysUntil(subscription.trial_ends_at)` with a direct field read `subscription?.trial_days_left ?? null`; drop the now-inapplicable React-Compiler comment (`:24-28`) about wrapping `daysUntil` in `useMemo` — the field read needs no comment about compiler memoization inference.
- [x] 11.6 `frontend/src/modules/checkoutSubscription/SelectPlanPage.test.tsx`: wherever a fixture feeds `trial_ends_at` and an assertion checks a day count in the rendered copy, add the matching `trial_days_left` to that fixture (the component no longer derives the count itself).
- [x] 11.7 Fix the pre-existing `react-hooks/rules-of-hooks` ERROR in the SAME file (design Decision 11, in scope because this phase already edits this hook region): in `OwnerPlanView`, hoist `isTrialBrowsing` (currently `:488`), `blockedReason` (`:489-495`), and `planPickerIsTheTask` (`:505-509`) — plus the two `useState` calls they feed, `showPlans`/`currentPlanKey` (`:510-511`) — to ABOVE the `if (isSuccess) { return <SuccessView /> }` early return (`:480-482`). Purely mechanical reordering; behavior-preserving (the hoisted values/state are simply unused when rendering `<SuccessView />`). This is what takes `pnpm lint` from red to green.

## Phase 12: Regression + cleanup (extension)

- [x] 12.1 `vendor/bin/pint --dirty` on all touched/created PHP files (`AppTimezoneCast.php`, `Subscription.php`, `User.php`, the new migration, `CalendarDays.php`, `SubscriptionStateService.php`, `ConfigurationController.php`, and the new/updated test files).
- [x] 12.2 `php artisan test --filter=Billing` (single process, `DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password`) — must be ≥292 passed (the measured post-Phase-7 baseline; must not regress).
- [x] 12.3 `php artisan test --filter=AppTimezoneCast --filter=CalendarDays` plus the Phase 9/10 feature suites — new coverage green.
- [x] 12.4 `pnpm vitest run` — gate on ≥760 tests and zero failures, NOT an exact count (the 4 `daysUntil` cases move backend, so the FE count legitimately shifts).
- [x] 12.5 `pnpm exec tsc -b` clean.
- [x] 12.6 `pnpm lint` clean — this is the regression gate that was RED before Phase 11.7 and must be green now.
- [x] 12.7 Grep sweep: zero `daysUntil` occurrences anywhere under `frontend/src/` (export deleted, zero call sites remain); zero hand-rolled `diffInDays`/day-count literals in `SubscriptionStateService.php` or `ConfigurationController.php` outside calls to `CalendarDays::until` — both excluding `backend/.claude/worktrees/`.
