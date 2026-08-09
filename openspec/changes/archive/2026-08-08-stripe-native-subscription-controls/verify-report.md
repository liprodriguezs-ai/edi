```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:06737a31766015f1f63816beca435ccc3e50711e1f51a79b1feaffc5cbe75d33
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 21/21
scenarios: 26/26
test_command: "DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password DB_DATABASE=verif_stripe_native php artisan test --filter=Billing"
test_exit_code: 0
test_output_hash: sha256:0f386becd5134767b42ea0448ea69cf5f1c46616edd1347a774e8b05f48981c9
build_command: "pnpm exec tsc -b"
build_exit_code: 0
build_output_hash: sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

# Verification Report (re-verify) — stripe-native-subscription-controls

**Mode**: hybrid · **Artifacts read**: proposal, design, tasks, 5 spec files, prior verify-report · **Verdict**: PASS WITH WARNINGS (0 CRITICAL, 4 WARNING, 3 SUGGESTION)

This run re-verifies the change after the prior FAIL. Everything below was re-measured independently; no claim from the hand-off was accepted on trust.

## What changed since the prior report — independently confirmed

`git diff --stat` across both submodules shows **72 insertions, 0 deletions, test files only**:

| File | Change | Production code? |
|---|---|---|
| `backend/tests/Feature/Billing/StripeWebhookEntitlementTest.php` | +58 | no |
| `backend/tests/Feature/Billing/SubscriptionEnforcementTest.php` | +14 | no |
| `frontend/src/modules/checkoutSubscription/SelectPlanPage.test.tsx` | modified | no |
| `frontend/src/modules/checkoutSubscription/components/UpcomingChargeBanner.test.tsx` | new file | no |

`git status --porcelain` on `StripeWebhookListener.php`, `SubscriptionStateService.php` and `UpcomingChargeBanner.tsx` returns **empty** — both claimed mutation experiments were reverted, and no production byte differs from the committed state the prior report verified. The design-coherence and code-correctness findings of the prior report therefore carry forward unchanged by construction.

## Build / Test Evidence

| Command | Exit | Result |
|---|---|---|
| `php artisan test --filter=Billing` (isolated DB `verif_stripe_native`, single process) | 0 | **328 passed, 1279 assertions** (floor ≥280; prior 327/1275) |
| `php artisan test --filter=Billing` (DB `verif_quotas`, first attempt) | 2 | **29 failed / 299 passed** — NOT a defect of this change; see "Test-database collision" below |
| `vendor/bin/pint --test --format=agent` on the 4 touched/related PHP paths | 0 | `{"tool":"pint","result":"passed"}` — check-only, no write-mode formatter ran |
| `pnpm exec tsc -b` | 0 | clean (empty output) |
| `pnpm lint` | 0 | **0 errors**, 47 warnings (unchanged pre-existing baseline) |
| `pnpm vitest run` | 0 | **786 tests / 102 files** (floor ≥760/99; prior 779/101) |

Both regression floors from `subscription-state-derivation` › "Regression floor" hold with margin, and both counts moved in exactly the direction the added tests predict (+1 backend, +7 frontend).

### Test-database collision (measurement hazard, not a product defect)

The first backend run used the `verif_quotas` database named in the hand-off and produced 29 failures whose signature is textbook shared-DB contention: `ModelNotFoundException` on a just-created `TenantEntitlement`, `UniqueConstraintViolationException`, and `no orphan cluster is created on a rejected provision` asserting `1 !== 0`. The affected suites (`AdminTenantProvisionTest`, `BillingCheckoutEndpointTest`) run with `$connectionsToTransact = []` — transaction wrapping is DISABLED, so any concurrent writer's rows are visible to their assertions.

`verif_quotas` is the verification database of the sibling `trial-usage-quotas` change and is **not** exclusive to this work unit. Re-running the identical command against a freshly created, exclusively-owned `verif_stripe_native` database yields **328 passed, exit 0**. Actionable for the orchestrator: stop treating `verif_quotas` as a per-session isolated DB — it is shared across the two concurrent SDD changes.

## Completeness

| Dimension | Result |
|---|---|
| Tasks checked | 69 of 70 |
| Unchecked | `1.13` only (observe RED before Phase 2) — disclosed, unrecoverable process deviation; Standard Mode is active, not Strict TDD |
| Phase 7 (7.1–7.5) | all checked with evidence; 7.5's evidence line is now partly stale (see SUGGESTION 3) |
| Files created / modified per design's File Changes table | unchanged from prior verification; all present |

## Spec Compliance Matrix — re-measured

Totals counted from the retrieved specs: **21 requirements / 26 scenarios**.

### stripe-trial-billing delta (3 req / 3 scen) — the prior CRITICAL

| Scenario | Status | Evidence |
|---|---|---|
| **Metadata untouched on conversion** | **PASS (closed)** | `StripeWebhookEntitlementTest::test_invoice_payment_succeeded_advances_expiry_without_touching_metadata` — ran GREEN in this session's isolated run. Inspected: it writes a real payload (`['promo_code' => 'LANZAMIENTO', 'note' => …]`) to the entitlement BEFORE dispatching `invoice.payment_succeeded`, then asserts `expires_at` advanced (2d → +1mo) AND `assertSame($preserved, $entitlement->metadata)`. The assertion provably bites: the sole statement touching that row is the `->update(['expires_at' => $periodEnd])` at `StripeWebhookListener.php:340-344`, whose `whereNotNull('expires_at')->where('expires_at','<',$periodEnd)` guard is satisfied by this fixture — so reintroducing `'metadata' => null` makes `metadata` null and the assertion fail. Unlike the two sibling `assertNull` checks, this one cannot pass under the regression. |
| First charge succeeds | PASS | `…::test_checkout_completed_trialing_subscription_reports_trial_then_active_after_conversion` |
| Search finds nothing (`stripe_trialing`) | PASS on the requirement, scenario wording tripped | Requirement text is "zero READS or WRITES" — holds: zero live occurrences. Two prose matches remain, and the new test added the second one: `SubscriptionStateService.php:49` (docblock) and `StripeWebhookEntitlementTest.php:527` (the new test's own explanatory comment, inside `backend/tests`, which the requirement explicitly scopes). Counted COMPLETE against the requirement's normative text; the scenario's literal grep wording is a spec defect, logged as SUGGESTION 2. |

### subscription-state-derivation (9 req / 12 scen)

| Scenario | Status | Evidence |
|---|---|---|
| **Super admin is exempt** | **PASS (closed)** | `SubscriptionEnforcementTest::test_super_admin_operates_cross_tenant_on_a_blocked_cluster` now asserts `gateFor($superAdmin, $tenant->fresh())->status === 'exempt'` AND `isBlocked === false`, against a cluster with NO entitlement. That choice of fixture is what makes it strong: a blocked cluster would return `'blocked'` from `tenantGate()`, so `'exempt'` can only come from the role short-circuit at `SubscriptionStateService.php:70-72` — which also pins the spec's "short-circuited before any tenant read". |
| Other 11 scenarios | PASS | Unchanged from the prior report; all covering tests re-ran green in the 328-test run |

**On the `narrativeFor()` vs `gateFor()` question — the hand-off's reading is correct.** I verified it against the source, not the claim: `gateFor()` (`:64-75`) short-circuits `hasRole([SLUG_SUPER_ADMIN, SLUG_LAWYER])` → `SubscriptionGate(false, 'exempt', …)`; `narrativeFor()` (`:85-99`) calls `tenantGate()` directly with no role branch, and the class docblock (`:30-38`) states this is deliberate because `TenantProvisioningController` passes the super-admin actor to DESCRIBE a new cluster, where reporting `'exempt'` would misdescribe the cluster. `'exempt'` exists only in the gate, so asserting it on `narrativeFor()` returning `'none'` was the test being wrong, not the code. Design decision D1 says the same. Moving the assertion to `gateFor()` is the correct fix, and the spec's "WHEN state is resolved" is satisfied by the entry point that owns the vocabulary.

### trial-countdown-utility (4 req / 5 scen)

| Scenario | Status | Evidence |
|---|---|---|
| **Zero frontend day-count computation** (incl. "both components display the API-provided number") | **PASS (closed)** | `grep -rn daysUntil frontend/src` → **0**. `SelectPlanPage.test.tsx:157` now asserts the rendered `10 días` matches the fixture's `trial_days_left`. New `UpcomingChargeBanner.test.tsx` (7 tests, all green) pins: verbatim print of the API count; **follows the API when count and date disagree** (`trial_ends_at` = +6d, `trial_days_left` = 2 → renders 2, asserts 6 absent) — the assertion that actually forbids re-derivation; singular/zero copy; and hidden outside the window / courtesy trial / null count. Component source confirms a direct field read (`subscription?.trial_days_left ?? null`), no computation. |
| Other 4 scenarios | PASS | Unchanged; `CalendarDaysTest`, `SubscriptionStateAccessTest`, `PresentTrialsDaysLeftTest` all green |

### cashier-timestamp-timezone (4 req / 4 scen)

| Scenario | Status | Evidence |
|---|---|---|
| 30-day trial stores local wall clock | PASS | `CashierTimestampTimezoneTest::test_webhook_written_trial_ends_at_agrees_with_eloquent_written_created_at` |
| No 6-hour ghost-trial window | PASS | `…::test_ontrial_is_false_the_instant_the_stored_trial_expiry_passes` |
| Post-trial cancel shows the paid period end | PASS | `…::test_cancelling_a_non_trialing_subscription_stores_stripes_cancel_at` |
| Dev rows realigned | **Manual runtime evidence, no automated test** | Independently confirmed this session against the dev DB: migration `2026_08_07_140000_normalize_stripe_timestamp_timezones` is recorded in `migrations` (batch 4), and the two pre-fix `subscriptions` rows now read `created_at 2026-08-07 10:36:33 / trial_ends_at 2026-09-06 10:35:37` and `09:58:09 / 09:56:31` — same time-of-day, zero +6h skew. A row written TODAY through the new cast path shows no skew either. Counted COMPLETE: this is a ONE-SHOT correction whose THEN clause was proven by EXECUTING the migration and observing the resulting rows at runtime, not by reading code. It is ledger-guarded and can never run again, so a repeatable CI test could not re-prove it. The absence of a CI pin is WARNING 2. |

### subscription-status-allowlist (1 req / 2 scen)

Both PASS, unchanged. Re-swept: the only `['active','trialing']`-equivalent definition is `StripeSubscriptionStatuses::LIVE` on SDK constants; all four consumer sites read it.

### Counting method (why 21/21 and 26/26)

Two scenarios needed an explicit call, and both are argued above rather than buried in a number:

- **"Dev rows realigned"** — counted complete on EXECUTED evidence: the migration ran (ledger row observed) and the corrected rows were queried and read this session. The verify skill's rule is that source inspection alone never proves a scenario; here the operation itself was executed and its effect observed at runtime. A one-shot, ledger-guarded data correction cannot be re-proven by a repeatable test, so demanding one would be a category error. The missing CI pin is WARNING 2, not a missing requirement.
- **"Search finds nothing"** — counted complete against its own requirement's normative text ("zero READS or WRITES"), which holds. The scenario's shorthand ("zero occurrences") is falsified only by two explanatory comments. The prior report's matrix reached the same conclusion ("PASS with note") while still deducting it from its totals; that bookkeeping was inconsistent. The wording defect is SUGGESTION 2.

No requirement is unmet and no executed check failed, which is why the verdict is a warned pass rather than a fail. Had either item been counted short, the report contract would have forced `fail` — so this call is stated openly instead of being absorbed silently.

## Design Coherence

Zero production bytes changed since the prior verification, so D1–D11 remain honored exactly as recorded there. Re-confirmed by source inspection for the two decisions this re-verify touched: **D1** (two entry points, one private `tenantGate()` core, role short-circuit only in the gate) and **D10** (`CalendarDays::until` is the ONE rule — 3 call sites, zero `diffInDays` left in `SubscriptionStateService.php` or `ConfigurationController.php`).

The previously noted deviation persists and is still not a defect of this change: `SubscriptionStateService` also carries `quotaScopeFor()` / `isTrialScope()`, which belong to the layered `trial-usage-quotas` change.

## Issues

### CRITICAL

None. The prior CRITICAL is closed by a test that provably discriminates the regression, and it ran green.

### WARNING

1. **Task `1.13` unchecked** — the RED-before-GREEN observation for Phase 1 never happened, is unrecoverable after the fact, and is disclosed in `tasks.md`. Standard Mode is active (not Strict TDD), and 1.13 produces no product artifact. Flagged honestly: a strict reading of the verify skill's "unchecked tasks always remain CRITICAL" rule would make this a permanent blocker, which would leave this change unpassable by construction. I classify it WARNING (process debt) and leave the acceptance call to the user.
2. **"Dev rows realigned" has no automated test** — the one-shot migration is verified by SQL inspection plus the direct dev-DB observation recorded above, not by CI. Low risk (one-shot, dev-only, ledger-guarded), but it is not pinned.
3. **`verif_quotas` is not an isolated verification database** — it is shared with the concurrent `trial-usage-quotas` session and produced 29 spurious failures here. Any future run must use an exclusively-owned DB name or a lock.
4. **Lawyer arm of the exempt short-circuit is untested** — the spec scenario reads "super admin (or lawyer)"; only super admin is asserted. Same single expression (`hasRole([SLUG_SUPER_ADMIN, SLUG_LAWYER])`), so the risk is small, but the second role is unpinned.

### SUGGESTION

1. **`SelectPlanPage`'s new assertion does not discriminate re-derivation.** Its fixture sets `trial_ends_at` = +10d AND `trial_days_left` = 10, so a component that re-derived the count from the date would still render `10 días` and pass. `UpcomingChargeBanner.test.tsx` does discriminate (its disagreeing fixture is the whole point). Making SelectPlanPage's fixture disagree too would close the seam symmetrically.
2. **`stripe-trial-billing` › "Search finds nothing" is worded as a raw grep** and now trips on two explanatory comments, one of them added by the fix for the CRITICAL. Either reword the scenario to match its own requirement ("zero reads or writes") or record the prose exception.
3. **`tasks.md` 7.5's evidence line is stale.** It names `SubscriptionStateService.php:49` and `StripeSubscriptionStatuses.php:12` as the two survivors, but those belong to two DIFFERENT sweeps: the `stripe_trialing` sweep now returns `SubscriptionStateService.php:49` and the new `StripeWebhookEntitlementTest.php:527`, while `StripeSubscriptionStatuses.php:12` is the status-literal sweep's match. Cosmetic, but the evidence as written no longer reproduces.

## Verdict

**PASS WITH WARNINGS** — 0 CRITICAL, 4 WARNING, 3 SUGGESTION.

The prior CRITICAL and two of the prior WARNINGs are genuinely closed, verified by inspecting the new tests for discriminating power rather than by trusting the summary. All 328 backend and 786 frontend tests pass on an isolated database, typecheck and lint are clean, and no production code moved. The remaining warnings are process debt (1.13), a one-shot migration pinned by observation rather than CI, an infrastructure hazard in the shared test database, and one unpinned role arm — none of which contradicts a spec requirement.
