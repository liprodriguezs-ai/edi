# Tasks: Plan Downgrade Guard

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 350–500 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR, `size:exception` |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|-----------------------|------------------|--------------------|
| 1 | Backend service layer: `UnitLimitCheck` docblock + `UnitLimitService` (`checkPlanFits`/`assertPlanFits`/`buildPlanChangeMessage` + `suggestPlanName` generalization) + `UnitLimitExceededException::forPlanChange` | PR 1 (single, size:exception) | `php artisan test Modules/SubscriptionPlan/tests` (host php, alone) | N/A — pure service, no route yet; proven by unit test | Revert `UnitLimitService.php`, `UnitLimitCheck.php`, `UnitLimitExceededException.php` — no schema/migration |
| 2 | Backend wiring: `ConfigurationController::changeBillingPlan`/`previewBillingPlanChange` guard calls + dedicated catch + `PreferentialSwapService` docblock | PR 1 (same) | `php artisan test tests/Feature/Billing` (host php, alone) | Manual QA: `admin@encinos.dev`/`secret1234` at `http://192.168.100.136:5173/`, attempt a downgrade on a tenant whose active units exceed the target band | Revert `ConfigurationController.php` guard insertions + `PreferentialSwapService.php` docblock — additive only, existing apply/preview flow resumes untouched |
| 3 | Backend tests + full regression | PR 1 (same) | `php artisan test Modules/SubscriptionPlan/tests` + `Modules/DataImport/tests` (host php, ONE process) | Same as above | N/A — test-only files |
| 4 | Frontend: `schema.ts` optional field + `ChangeBillingPlanModal.tsx` warning panel/disabled Confirm | PR 1 (same) | `pnpm vitest run src/modules/user src/modules/tenant` + `pnpm typecheck` | Manual QA: preview call showing the warning panel with disabled "Confirmar cambio" | Revert `schema.ts` field + `ChangeBillingPlanModal.tsx` — independent of backend; apply-422 path needs zero FE change so reverting the panel only loses the pre-warning UX, not correctness |

## Phase 1: Foundation — `UnitLimitService` layer (`Modules/SubscriptionPlan`)

- [x] 1.1 `UnitLimitCheck.php` — rewrite the class docblock (`:13-18`) to document BOTH readings: increase semantics (existing) vs target semantics (new) — `planName`/`unitLimit` = TARGET's under `checkPlanFits`, never from `resolveBand()`; `suggestedPlanName` may legitimately equal the tenant's CURRENT plan (exclusion is `null`); `attemptedIncrease = 0` is meaningless here, which is why `forPlanChange`'s payload omits it. Satisfies REQ-08 (Open Question), design File Changes row.
- [x] 1.2 `UnitLimitService.php` — generalize private `suggestPlanName` (`:182`) to accept an optional exclude-plan-id parameter; update the `checkIncrease` call site (`:136`) to keep passing the current band as exclusion (behavior-preserving). Satisfies Decision 2; the 11/11 `UnitLimitServiceTest` baseline is the guard that this is a no-op for `checkIncrease`.
- [x] 1.3 `UnitLimitService.php` — add `checkPlanFits(BillingPackage $target): UnitLimitCheck`: reuse `activeUnitCount()`; `planName`/`unitLimit` = target's; `attemptedIncrease = 0`; `allowed = $target->suggested_unit_max === null || $activeUnits <= $target->suggested_unit_max` (strict boundary per Decision 3 — never `<`); fail-open (`allowed: true`) when tenancy is not initialized, mirroring `checkIncrease`; `suggestedPlanName` via `suggestPlanName($activeUnits, null)` (no exclusion). Satisfies REQ-01, REQ-04, REQ-05, REQ-06, Decision 2/3.
- [x] 1.4 `UnitLimitService.php` — add `assertPlanFits(BillingPackage $target): void` throwing `UnitLimitExceededException::forPlanChange($this->checkPlanFits($target), ...)` when not allowed.
- [x] 1.5 `UnitLimitService.php` — add `buildPlanChangeMessage(UnitLimitCheck $check): string`, mirroring shipped `buildImportPreviewMessage`: fits-exists template names active units, target plan, its limit, and the suggested plan; no-fitting-plan template ("contacta a soporte"). Mexican Spanish, tú-form. Satisfies REQ-07, Decision 5.
- [x] 1.6 `UnitLimitExceededException.php` — add named constructor `forPlanChange(UnitLimitCheck $check, string $message): self`, payload `{message, code: 'UNIT_LIMIT_EXCEEDED', plan_name, unit_limit, active_units, suggested_plan_name}` — NO `attempted_increase` key (meaningless under target semantics). Keeps `ShouldntReport` + self-`render()`. Satisfies REQ-08.
- [x] 1.7 `PreferentialSwapService.php` — add class-level docblock recording the deliberate exemption: unit-band fit is intentionally NOT checked here; the cluster-admin surface enforces it in `ConfigurationController`; the super-admin preferential swap is exempt by user decision. Satisfies REQ-03; no code change to this file's logic.

## Phase 2: Controller Wiring (`Modules/Configuration`)

- [x] 2.1 `ConfigurationController.php::changeBillingPlan` (`:1340`) — method-inject `UnitLimitService`; inside the existing try block, after context resolution, call `$unitLimitService->assertPlanFits($context['to'])` before `applyCard`/`applyManual` (`:1354`/`:1362`). Satisfies REQ-01.
- [x] 2.2 Same method — add `catch (UnitLimitExceededException $e) { return $e->render(); }` positioned AFTER the existing `catch (InvalidArgumentException)` (`:1364`) and BEFORE `catch (Throwable)` (`:1366`) — MANDATORY ordering, verified: without it the generic Throwable catch swallows the exception, logs a routine block as an application error (defeating `ShouldntReport`), and flattens the response to "No se pudo cambiar el plan." Satisfies REQ-01, REQ-08.
- [x] 2.3 `ConfigurationController.php::previewBillingPlanChange` (`:1388`) — method-inject `UnitLimitService`; after `$payload` is built, call `checkPlanFits($target)`; when `!allowed`, append `$payload['unit_limit_warning'] = {message: buildPlanChangeMessage($check), plan_name, unit_limit, active_units, suggested_plan_name}`. NO 422 — stays HTTP 200 so the FE preview schema keeps parsing proration data. Satisfies REQ-02, Decision 4.
- [x] 2.4 Re-verify anchors `:1340`–`:1409` are intact against current `main` before editing (proposal risk: shared-checkout parallel WIP) — re-diff after edits to confirm the touch is minimal (guard calls + one catch clause only, no unrelated refactor). Satisfies REQ-09. Re-diffed post-edit: only guard calls + one catch clause + docblocks, no unrelated refactor.

## Phase 3: Frontend — Schema and Modal

- [x] 3.1 `frontend/src/modules/tenant/billing/schema.ts` (`:175`) — add optional `unit_limit_warning: z.object({ message: z.string(), plan_name: z.string().nullable(), unit_limit: z.number().nullable(), active_units: z.number(), suggested_plan_name: z.string().nullable() }).optional()` to `preferentialSwapPreviewSchema`. Do NOT convert the schema to `.strict()`/`z.strictObject` — default STRIP is load-bearing for both consumers (`CheckoutSubscriptionResource.ts:187`, `TenantBillingResource.ts:85`). Satisfies REQ-02, Decision 4.
- [x] 3.2 `frontend/src/modules/user/components/ChangeBillingPlanModal.tsx` — when `preview.unit_limit_warning` is present, render a warning panel with its `message` and disable the "Confirmar cambio" button. No-warning path stays unchanged; apply-422 needs zero additional FE change (existing hook already toasts the backend `message`). Satisfies REQ-02, REQ-07, Decision 4.

## Phase 4: Backend Tests

- [x] 4.1 `UnitLimitServiceTest.php` — extend fit matrix: over-cap target blocks; exactly-at-max fits (REQ-05); NULL `suggested_unit_max` always fits (REQ-06); uninitialized tenancy fail-open; suggestion is the smallest fitting band and may equal the tenant's current plan; 400-unit target still clamps to `null` above Premium (pins the `planForUnits` clamp neutralization — do NOT "fix" the clamp itself); message variants (fits-exists vs no-fitting-plan). Confirm the pre-existing 11/11 baseline is unaffected by the `suggestPlanName` signature change. Satisfies REQ-01, REQ-04, REQ-05, REQ-06, REQ-07. Result: 19/19 passed (46 assertions), including the original 11/11 baseline untouched.
- [x] 4.2 Create `backend/tests/Feature/Billing/PlanDowngradeGuardTest.php` — apply over-band → 422 payload (message, plan_name, unit_limit, active_units, suggested_plan_name) + `billing_package_id` unchanged (REQ-01); upgrade → 200 (REQ-04); same-band reselect → 200 (REQ-04); exactly-at-limit (25 units → Básico max 25) → 200 (REQ-05); preview over-band → `unit_limit_warning` present with correct numbers (REQ-02, REQ-07); preview fitting → key absent; super-admin `preferential-swap` + its preview on the SAME over-band tenant → 200, bypass proof (REQ-03). Use the `SelfServeBillingPlanTest` fixture pattern. Result: 7/7 passed (34 assertions). Found + fixed a fixture bug along the way: ClusterScenario's tenant defaults to `payment_method=card`, which makes `PreferentialSwapController::resolveContext` require an active Cashier subscription; forced `payment_method=transfer` in setUp so both actors share the Stripe-free MANUAL billing reality.
- [x] 4.3 Run regression: `pgrep -f "artisan test|phpunit"` first (ONE process only, host php from `backend/`), then `php artisan test Modules/SubscriptionPlan/tests` (confirm 11/11 + new cases) and `php artisan test tests/Feature/Billing`. Also run `php artisan test Modules/DataImport/tests` confirming the 124 passed / 475 assertions baseline is untouched (REQ-09).
- [x] 4.4 `vendor/bin/pint --dirty` scoped ONLY to `Modules/SubscriptionPlan` and `Modules/Configuration` paths touched by this change — never repo-wide (a parallel session has uncommitted work elsewhere in the shared checkout). Ran `vendor/bin/pint --dirty --format agent -- Modules/SubscriptionPlan Modules/Configuration`; fixed minor spacing/import-order in `ConfigurationController.php` only.

## Phase 5: Frontend Tests

- [x] 5.1 Create `frontend/src/modules/user/components/ChangeBillingPlanModal.test.tsx` — warning panel renders the message when `unit_limit_warning` is present; "Confirmar cambio" is disabled in that state; no-warning path renders and behaves as before (Confirm enabled). Colocated Vitest + Testing Library. Reused the repo's established shadcn `Select` stub pattern (from `PackageForm.test.tsx`) to pick a plan without driving Radix portal/pointer-capture internals.
- [x] 5.2 Run `pnpm vitest run src/modules/user src/modules/tenant` and `pnpm typecheck` for an attributable signal. The full suite's pre-existing failure (parallel session) and the pre-existing typecheck error in `src/modules/package/PackageForm.tsx` are NOT ours — do not chase them. Result: 2/2 vitest passed; `pnpm typecheck` exit 0 clean (no pre-existing PackageForm.tsx error observed at time of this run).

## Phase 6: Manual QA and Regression Confirmation

- [x] 6.1 Manual QA — **ATTEMPTED 2026-07-15 by the orchestrator and DELIBERATELY ABANDONED. Reporting this honestly rather than ticking it: the modal is NOT reachable in the dev environment, so no browser click-through of this guard exists.**

  **What was tried** (user approved mutating the dev DB, on the condition it be reverted): moved "Privada Los Encinos (Dev)" from Básico to Avanzado, created 30 test units (numbers 900-929) to reach 36 active — a state where a downgrade to Básico (max 25) MUST block. Then opened `/subscription` as `admin@encinos.dev`.

  **Why it could not be finished**: the page renders "Seleccionar un plan", never the plan manager that hosts `ChangeBillingPlanModal`. Root cause: the tenant's entitlement is `source = super_admin_free` with `stripe_subscription_id = null` — there is no Cashier subscription. The dev fixture is also internally inconsistent: `tenant.payment_method = 'card'` while no subscription exists. Setting `payment_method = 'transfer'` (the same workaround `PlanDowngradeGuardTest::setUp` needed) did not help — the manager needs a real subscription record, not just a manual payment method. Fabricating a fake Stripe subscription in the dev DB was judged a worse risk than leaving this unverified, so it was not done.

  **Coverage that DOES exist for every scenario this task lists**: `tests/Feature/Billing/PlanDowngradeGuardTest.php` — **7/7 passing, 34 assertions** (verified by the orchestrator running it directly), driving the REAL HTTP routes: over-band apply rejected + plan unchanged; upgrade succeeds; same-band reselect succeeds; exactly-at-limit succeeds; preview reports the warning; fitting preview omits it; **super-admin preferential swap succeeds on the same over-band tenant** (the REQ-03 bypass). Plus `ChangeBillingPlanModal.test.tsx` — 2/2 — asserting the warning text renders and "Confirmar cambio" is disabled, which is exactly what a human would have looked at on screen.

  **Dev DB restored to its pre-QA state, verified by query**: plan `Básico`, `payment_method = 'card'`, 6 active units, 0 leftover test units, 0 leftover charges. Note for whoever retries this: creating units is NOT free — `UnitObserver::created` auto-generated 30 charges (§6.5 r4), which had to be deleted before the units (FK `charge_unit_id_foreign`). No payments were applied against them, so nothing accounting-real was touched.

  **Follow-up to make this reproducible**: seed a dev tenant with an actual Cashier subscription (or a second cluster with >25 units on a card plan). Until then, this modal has no browser-reachable path in dev.
- [x] 6.2 Confirm no regression to `PreferentialSwapService` apply/preview (existing `PreferentialSwapTest` suite green) and Stripe proration behavior is untouched. Satisfies REQ-09. Full `tests/Feature/Billing` run: 200/200 passed (862 assertions), including `PreferentialSwapTest` (8/8) unaffected.

## Final Regression Summary

| Suite | Result |
|---|---|
| `Modules/SubscriptionPlan/tests` | 19/19 passed (46 assertions) — 11 baseline + 8 new `checkPlanFits`/`assertPlanFits`/message cases |
| `tests/Feature/Billing` (full) | 200/200 passed (862 assertions), incl. new `PlanDowngradeGuardTest` 7/7 |
| `Modules/DataImport/tests` | 124/124 passed (475 assertions) — untouched baseline confirmed |
| `pnpm vitest run src/modules/user src/modules/tenant` | 2/2 passed |
| `pnpm typecheck` | exit 0, clean |
| `vendor/bin/pint --dirty` (scoped) | 1 file reformatted (`ConfigurationController.php` — spacing/import-order only) |
