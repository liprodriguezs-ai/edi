```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:09b1382e716f4638d0a8ed6d1976ddaefdc92ad54c1048a8fcdf19e81a9cf23e
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 20/20
scenarios: 24/24
test_command: php artisan test --filter=Quota
test_exit_code: 0
test_output_hash: sha256:dda09a49a73c9e07e20b301869ba87a9c9cf54fe0c7f31f5a2f719a0c68dd365
build_command: pnpm exec tsc -b
build_exit_code: 0
build_output_hash: sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

## Verification Report (FINAL — RE-VERIFY #4)

**Change**: `trial-usage-quotas`
**Version**: spec dated 2026-08-07, `quota-visibility` amended post-verify-#1 — 5 capability files, **20 requirements / 24 scenarios** (counted this session with `grep -c '^### Requirement'` / `'^#### Scenario'`, not inherited)
**Mode**: Standard (no Strict TDD)
**Implementation revision**: backend HEAD `0f0e4f1`, unchanged since verify #3. `git diff HEAD` over every production path this change owns (`Modules/SubscriptionPlan/app`, `Modules/Housing/app`, `Modules/Access/app`, `Modules/AmenityReservations/app`, `Modules/Configuration/app`, `app/Services`) is **empty**; `frontend/src/shared/api/apiClient.ts` is likewise undiffed. **Zero production bytes changed since verify #3** — the delta is two test files.
**Previous verdict**: FAIL, 1 CRITICAL (REQ-QV-02 untested), 6 warnings.

### Headline

**Both items I was asked to confirm are genuinely closed, and I checked them rather than accepting the claims.** REQ-QV-02 now has runtime coverage on the frontend side, and — importantly — I traced the chain past the test itself to confirm the two flows the requirement is about (`ReserveAmenityWizard`, `VisitorFormModal`) really do route their 422 through the exact function the test pins. WARNING-5's `assertAndConsume()` guard is now pinned by a test that dies under its own mutation. That closes the last CRITICAL: **24/24 scenarios, 20/20 requirements, zero blockers.**

**Two things the handoff did not say, both of which I am reporting rather than passing silently:**

1. **`--filter=Access` is worse than verify #3 measured: 92 passed / 2 failed, against a stated floor of 93 / 1.** The extra failure is `ClusterLifecycleTest > 09 access qr scan flow`. I did not accept it as "probably foreign" — I isolated the mechanism and proved it. It is a **clock-dependent, pre-existing bug** in code this change never touched, and it would fail identically on the pre-change source at this hour. Full proof below (WARNING-1). It does not block, and I state plainly that a named regression floor is numerically below its baseline.
2. **One of the four new frontend tests is tautological.** The fourth (`never routes a quota rejection to the permissions modal`) passes for *any* payload, and the property its comment claims is in fact **false** in the real path. I proved both with a throwaway probe, then deleted the probe. It does not affect compliance — the spec scenario does not require that property — but it is exactly the "green test that proves nothing" class verify #3 rightly refused to bank (WARNING-2).

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 28 |
| Tasks complete (`### [x]`) | 28 |
| Tasks incomplete (any form) | 0 |

Counted this session: `grep -cE '^### \[x\]'` = 28, `grep -cE '^### \[ \]'` = 0, `grep -cE '^\s*- \[ \]'` = 0, `grep -cE '^### \['` = 28, and `grep -nE '\[ \]' tasks.md` returns **nothing at all**.

**Tasks format — restated as asked, and I agree with verify #3.** `tasks.md` uses `### [x] T1` headings instead of `- [x]` list items, so the native dispatcher reports "tasks.md has no markdown task checkboxes". That message is its list-item regex failing to match a heading, not a finding about the work. The semantic content the check exists to protect — "is any task still open?" — is answerable and answered: 28 tasks, all checked, zero open, no `[ ]` anywhere in the file. **This must not block archive.** Convert the markers afterwards so future dispatcher runs are quiet (SUGGESTION-7).

### Build & Tests Execution (every number re-measured this session; nothing inherited)

Backend, exclusive database `verif_quotas` on `127.0.0.1:5440`, **one process at a time**, `ps aux | grep "[a]rtisan test"` = 0 before starting. (The container was down at session start and was brought up with `docker compose up -d pgsql`; the first run failed with `SQLSTATE[08006] Connection refused` and was re-run after the container was healthy — that first failure is an environment artifact, not a result.)

| Command | Exit | Result | Verify-#3 baseline | Verdict |
|---|---|---|---|---|
| `--filter=Quota` | 0 | **41 passed / 125 assertions** | 40 / 123 | ✅ +1 test / +2 assertions = exactly the new `assertAndConsume` test |
| `--filter=UnitLimit` | 0 | **42 / 133** | 42 / 133 | ✅ byte-identical |
| `--filter=Housing` | 0 | **110 / 339** | 110 / 339 | ✅ identical |
| `--filter=AmenityReservations` | 0 | **213 / 803** | 213 / 803 | ✅ identical |
| `--filter=Billing` | 0 | **330 / 1287** | 330 / 1287 | ✅ identical |
| `--filter=Access` | 1 | **92 passed, 2 failed** | 93 passed, 1 failed | ⚠️ one MORE failure — WARNING-1, proven foreign |
| `pint --test --format agent` (Services, Exceptions, 2 touched test files) | 0 | `{"tool":"pint","result":"passed"}` | passed | ✅ |

Frontend:

| Command | Exit | Result | Baseline | Verdict |
|---|---|---|---|---|
| `pnpm exec tsc -b` | 0 | zero bytes of output | — | ✅ |
| `pnpm lint` | 0 | **0 errors**, 47 warnings | 0 errors, 47 warnings | ✅ identical |
| `pnpm vitest run` | 0 | **103 files / 790 tests passed** | 102 / 786 | ✅ +1 file / +4 tests = exactly the new file |
| `pnpm vitest run src/shared/api/quotaExceeded.test.ts` | 0 | **1 file / 4 tests** | n/a | ✅ the new file alone |

**Coverage**: ➖ not measured (no coverage gate in this repo).

### CRITICAL-1 (verify #3) — REQ-QV-02 uncovered on the frontend: **CLOSED**

The new file is `frontend/src/shared/api/quotaExceeded.test.ts` (4 tests, all green in the 790-test run).

**Scenario clause 1 — "the user sees the backend's Mexican-Spanish quota message naming the resource and the limit".**

Test 2 builds an `ApiError(…, 422, BACKEND_422)` whose payload is copied field-for-field from `QuotaExceededException::render()`, and asserts `formatApiErrorMessage()` returns the backend string as `title`, plus four independent substring assertions: the limit (`Alcanzaste el límite de 5`), the resource label (`registros de visitantes`), the plan name (`Esencial`), and tú-form (`Actualiza tu plan`) per the project's Mexican-Spanish rule.

**Mutation sensitivity — traced against source, not assumed.** `formatApiErrorMessage` (`apiClient.ts:56-67`) computes `title = payload?.message ?? apiError.message ?? 'Error al guardar'`. Replace that with a hard-coded `'Error al guardar'` and test 2's `expect(title).toBe(BACKEND_422.message)` fails, and test 3's `expect(title).not.toBe('Error al guardar')` fails independently — 2 of 4, exactly as the handoff claims. The trace is a single deterministic expression; I did **not** run the mutation, for the same reason verify #3 declined: this checkout is shared with parallel sessions and a window with `apiClient.ts` mutated could inject a spurious failure into another agent's run. Where I *could* mutate safely — a throwaway probe file of my own — I did, and it changed a conclusion (see WARNING-2).

**What the test does NOT prove, and the extra step I took because of it.** The test exercises the shared formatter, not a rendered form. So I checked the two flows the requirement is actually about, and the chain holds end to end:

- `frontend/src/modules/reservation/components/ReserveAmenityWizard.tsx:281` — `error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo crear la reservación'`
- `frontend/src/modules/access/components/VisitorFormModal.tsx:178` — `error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo registrar la visita'`

Both quota-guarded surfaces pass the ApiError through the exact function the test pins, and the result is handed straight to `toast.error(...)`. The scenario's GIVEN names `amenity_reservation` while the fixture uses `visitor`; `formatApiErrorMessage` does not branch on resource, so this is immaterial (same treatment verify #3 gave the analogous mismatch in REQ-CT-05).

**Scenario clause 2 — "the code string the frontend holds is byte-identical to the one the backend emitted".**

Test 1 asserts `BUSINESS_CODES.QUOTA_EXCEEDED === 'QUOTA_EXCEEDED'` against a **hard-coded literal**, with a comment explaining that comparing the constant to itself would prove nothing. That is the right construction, and it is the exact defect class (`'unit_limit_reached'` vs `UNIT_LIMIT_EXCEEDED`) the requirement exists to prevent. Confirmed against `QuotaExceededException.php:32` — `private const CODE = 'QUOTA_EXCEEDED';`.

**Residual, recorded as WARNING-3**: the backend message in the fixture is hand-copied, not shared. If `buildMessage()`'s wording changes, this test stays green while the assertion it makes about the backend becomes false. The code half is protected against drift by construction; the message half is not.

**REQ-QV-02 is compliant.**

### WARNING-5 (verify #3) — `assertAndConsume()`'s tenancy guard unpinned: **CLOSED**

`QuotaServiceTest::test_assert_and_consume_fails_open_without_tenancy_and_writes_nothing` (green, and visible by name in this session's run: `✓ assert and consume fails open without tenancy and writes nothing`).

The setup is the part that makes it real: it seeds a global `visitor` quota of 1 **and** a `TenantQuotaUsage` row already **at** that limit, so the only expression that can let the call through without throwing is the guard itself. It then ends tenancy, calls `assertAndConsume()`, and asserts no throw, no new counter row, and no counter movement.

**Mutation sensitivity — traced both branches.** Delete `if (! tenancy()->initialized) { return; }` at `QuotaService.php:227` and:
- `checkConsumption()` fails open first (returns `allowed: true, limit: null`), so the exception path does not save the test;
- execution reaches `consume()`, which under `RefreshDatabase`'s wrapping transaction passes the `transactionLevel() === 0` check, finds the row unscoped (`TenantScope::apply()` early-returns without tenancy), sees `$check->limit === null` so the REQ-CT-05 re-check does not fire, and writes `used_count = 2` at `:188`.
- The final assertion — "the counter must not move without tenancy" — fails.

So the guard is no longer deletable with a green suite. `grep` this session confirms `assertAndConsume` now has exactly 2 production callers (`AccessService.php:171`, `ReservationService.php:133`) and 1 test caller (the new one), up from zero.

### Spec Compliance Matrix

| Requirement | Scenario | Test | Result |
|---|---|---|---|
| REQ-QC-01 | a quota row expresses a trial cap | `TrialQuotaCatalogTest > test_a_global_trial_row_resolves_its_limit` | ✅ COMPLIANT |
| REQ-QC-02 | plan row overrides global row | `TrialQuotaCatalogTest`; `QuotaServiceTest` (2 tests) | ✅ COMPLIANT |
| REQ-QC-03 | unlimited never blocks at any usage level | `TrialQuotaCatalogTest`; `QuotaServiceTest` | ✅ COMPLIANT |
| REQ-QC-04 | second global row for the same key is rejected | `TrialQuotaCatalogTest > test_a_second_global_row_..._rejected_by_the_database` | ✅ COMPLIANT |
| REQ-QC-05 | rerun preserves an operator-edited value | `TrialQuotaCatalogTest` (2 tests) | ✅ COMPLIANT |
| REQ-CT-01 | creating a reservation consumes one | `TrialQuotaConsumptionTest` (2 tests) | ✅ COMPLIANT |
| REQ-CT-02 | failed reservation leaves the counter untouched | `TrialQuotaConsumptionTest > test_a_rolled_back_reservation_transaction_consumes_zero_quota` | ✅ COMPLIANT (mechanism caveat: WARNING-5) |
| REQ-CT-03 | deleting a visit does not free quota | `TrialQuotaConsumptionTest` (2 tests) | ✅ COMPLIANT |
| REQ-CT-04 | physical entries after the QR cost nothing | `TrialQuotaConsumptionTest > test_physical_entries_and_exits_after_the_qr_cost_nothing` | ✅ COMPLIANT |
| REQ-CT-04 | guard-initiated visitor consumes nothing | `TrialQuotaConsumptionTest > test_guard_initiated_entry_request_consumes_nothing_even_at_the_limit` | ✅ COMPLIANT |
| REQ-CT-05 | one slot, two racers, one winner | `test_a_racer_that_commits_between_check_and_consume_loses_the_last_slot` (behavioral) + `test_consume_selects_the_counter_row_for_update` (structural) + `test_sequential_consumption_at_the_boundary_never_exceeds_the_quota` | ✅ COMPLIANT (simulated concurrency — WARNING-6) |
| REQ-QE-01 | reaching the limit succeeds, exceeding blocks | `TrialQuotaEnforcementTest` (3 tests) | ✅ COMPLIANT |
| REQ-QE-02 | quotas stop applying when the trial ends | `TrialQuotaEnforcementTest > test_quotas_stop_applying_once_the_tenant_becomes_active` | ✅ COMPLIANT |
| REQ-QE-03 | rejection payload is complete | `TrialQuotaEnforcementTest > test_rejection_payload_carries_the_full_stable_contract` | ✅ COMPLIANT |
| REQ-QE-04 | no resolvable quota allows | `TrialQuotaEnforcementTest`; `QuotaServiceTest` | ✅ COMPLIANT |
| REQ-QE-04 | no tenancy allows | `TrialQuotaEnforcementTest > test_no_tenancy_initialized_allows`; `QuotaServiceTest > test_no_tenancy_initialized_fails_open`; **`QuotaServiceTest > test_assert_and_consume_fails_open_without_tenancy_and_writes_nothing` (NEW)** | ✅ COMPLIANT (both entry points now pinned) |
| REQ-QE-05 | seeded system is behaviorally invisible | `TrialQuotaEnforcementTest` + `--filter=UnitLimit` 42/42 | ✅ COMPLIANT |
| REQ-QE-06 | (no scenario — regression floor) | see execution tables | ✅ COMPLIANT (Access batch caveat: WARNING-1) |
| REQ-QV-01 | trial tenant sees its quota state | `TrialQuotaVisibilityTest > test_config_subscription_reports_visitor_quota_limit_and_usage` | ✅ COMPLIANT |
| REQ-QV-02 (amended) | quota 422 reaches the user with the real reason | backend half: `test_rejection_payload_carries_the_full_stable_contract`; **frontend half: `quotaExceeded.test.ts` tests 1-3 (NEW)** | ✅ **COMPLIANT** (was UNTESTED — fixture-drift caveat: WARNING-3) |
| REQ-12 | trial quota stricter than the band blocks first | `TrialQuotaUnitLimitDeltaTest`; `QuotaServiceTest` | ✅ COMPLIANT |
| REQ-12 | band stricter than the quota still governs | `TrialQuotaUnitLimitDeltaTest`; `QuotaServiceTest` | ✅ COMPLIANT |
| REQ-12 | deleting a unit frees quota room | `TrialQuotaUnitLimitDeltaTest > test_deleting_a_unit_frees_quota_room` | ✅ COMPLIANT |
| REQ-12 | unlimited quota leaves the band alone | `TrialQuotaUnitLimitDeltaTest`; `QuotaServiceTest` | ✅ COMPLIANT |
| REQ-13 | band rejection opens the upgrade dialog | `UnitForm.test.tsx` (green in the 790-test run) | ✅ COMPLIANT |

**Compliance summary**: **24/24 scenarios compliant; 20/20 requirements complete.**

### Correctness (Static + Structural Evidence, re-run this session)

| Check | Result |
|---|---|
| Production source changed since verify #3 | **No** — `git diff HEAD` empty on every owned path; HEAD still `0f0e4f1` |
| `used_count` write paths outside tests | Exactly ONE: `QuotaService.php:188`, plus the `0` initializer at `:204` |
| Any decrement anywhere | **None** — REQ-CT-03 holds by construction |
| Guarded production flows | Exactly 4 call sites: `UnitObserver::creating()` + `restoring()` (`assertCanRegisterUnits`), `ReservationService.php:133`, `AccessService.php:171` (`assertAndConsume`) |
| `assertAndConsume()` callers in tests | **1** (was zero) — WARNING-5 from verify #3 closed |
| Frontend `QUOTA_EXCEEDED` references | 2: the definition at `businessCodes.ts:13` and the new test file. Still zero production consumers — by design (D9, amendment) |
| `plan_quota.billing_package_id` column comment **in the live database** | **NULL** — WARNING-4 confirmed at runtime, not only statically |

### Tenancy Audit (stancl-tenancy)

Unchanged and re-confirmed: `tenant_quota_usage` uses `BaseModel::columns($table, true)` + `BelongsToTenant` with a `tenant_id`-scoped unique index; `PlanQuota` is correctly central; no `DB::` facade for tenant data; the counter cannot leak across clusters on any public guard entry point. `consume()` remains `public` and unguarded but is unreachable from production (SUGGESTION-6).

### Coherence (Design)

D1-D9 were verified as followed in verify #2 and re-confirmed in verify #3 against source that is byte-identical today, so that judgment carries with a concrete basis rather than by inheritance. **D9** ("business codes get ONE frontend home") is the one this round strengthens: it is now not only implemented but pinned by a test that fails if the constant drifts from the backend's literal.

### Issues Found

**CRITICAL**

None.

**WARNING**

1. **`--filter=Access` is 92 passed / 2 failed, one failure MORE than verify #3's measured floor of 93 / 1 — and I am reporting the shortfall rather than rounding it away.** The two failures are `ChatAccessMatrixTest > global board role witho…` (verify #3's known-foreign Chat ordering artifact) and, new this session, `ClusterLifecycleTest > 09 access qr scan flow`.
   **The new one is proven foreign, by mechanism, not by assertion:**
   - The 422 is **not** a quota rejection. It is `AccessLogController::scanQr:54` returning `$result['valid'] ? 200 : 422` — `AccessService::scanQr` judged the QR invalid. `QuotaExceededException` cannot reach that line; `scanQr` never calls `QuotaService`.
   - In the same test, the quota-guarded call (`POST /api/visitor` → `generateQr` → `assertAndConsume`) returned **201**. The guard let it through; the failure is downstream.
   - `git show 0f0e4f1 -- Modules/Access/app/Services/AccessService.php` shows this change touched **only** `generateQr` (added the constructor dependency, wrapped it in `DB::transaction`, added one `assertAndConsume` line). `git log -S resolveExpiry` says `resolveExpiry` has not changed since the initial commit (`9c135d6`, 2026-06-05).
   - **Root cause, computed rather than guessed**: `resolveExpiry(SINGLE_ENTRY)` returns `Carbon::now($tenantTz)->endOfDay()`. The E2E scenario tenant is `America/Mexico_City` (`ClusterScenario.php:163`) while `.env.testing` sets `APP_TIMEZONE=UTC`. Eloquent stores the wall-clock string and drops the offset, so `2026-08-08T23:59:59-06:00` is persisted as `2026-08-08 23:59:59` and read back as **UTC**. Verified by direct computation: with `APP_TIMEZONE=UTC`, read-back `2026-08-08T23:59:59+00:00` versus `now() = 2026-08-09T04:55:06+00:00` gives `isPast() = true` — the QR is **born expired**. This bites whenever local time passes the UTC-offset boundary (after 18:00 America/Mexico_City).
   - **Falsifiable prediction**: re-run `--filter=Access` before 18:00 America/Mexico_City, with zero code change, and test 09 goes green — restoring the 93 / 1 floor exactly. This session ran at 22:53 local; verify #3 ran at ~11:04 local.
   - The same file also fails `04 collections concept batch payments and fine` and `12 end to end financial coherence` in isolation, both on `CreditBalance` — Collections code this change does not touch at all.
   **This is a genuine product bug worth its own ticket** (a resident's single-entry QR is unusable after 6 PM whenever the deployment's `APP_TIMEZONE` is behind the tenant's), but it is not this change's, and it is not a reason to hold this change.
2. **The fourth new frontend test is tautological, and the property it claims is false.** `never routes a quota rejection to the permissions modal` calls `handle403` with `skipPermissionsModal: true`, which returns at `apiClient.ts:202` **before any payload inspection** — so the assertion holds for any payload whatsoever. I proved it with a throwaway probe (since deleted, repo left clean): **PROBE A** passed with a payload carrying no `code` at all; **PROBE B** showed that a real `QUOTA_EXCEEDED` payload *without* `skipPermissionsModal` **does** open the permissions modal (`isOpen === true`), because `QUOTA_EXCEEDED` is not in `handle403`'s early-return list the way `DELINQUENT_RESTRICTED` is. Harmless today — the backend renders 422, so `handle403` is unreachable for quota — but the test's own comment asserts a safety property that neither the test nor the code provides. Either delete it or make it real by asserting the 403 path and adding the early return.
3. **REQ-QV-02's message assertion rests on a hand-copied fixture.** `BACKEND_422.message` is a literal transcribed from `QuotaExceededException::buildMessage()`. Reword the backend message and the test stays green while its premise quietly stops being true. The `code` half is protected (hard-coded literal, exactly right); the message half is not. A golden generated from the backend, or a backend test asserting the same literal, would close it.
4. **`plan_quota.billing_package_id` still ships with NO column comment** (project strict rule 9). **Confirmed at runtime this session**, not just by reading the migration: `col_description` for that column in `verif_quotas` is NULL while `scope`, `resource_key` and `limit_value` all carry theirs. Cause unchanged: `->comment()` is chained after `->references('id')->on('billing_package')`, which returns a `ForeignKeyDefinition`, so the comment lands on the FK command and is never emitted.
5. **The REQ-CT-02 rollback test does not detect the failure its own comment documents.** Unchanged from verify #2/#3. Orion wraps the controller action in its own transaction, so `consume()`'s `DB::transactionLevel() === 0` self-check never fires on an HTTP path. The end-to-end property is genuinely pinned; the *placement* claim is not.
6. **REQ-CT-05's concurrency is simulated, not executed.** Unchanged. The re-check is behaviorally pinned and the lock is structurally pinned, but no test runs two real racers against the database — that needs two OS processes. Worth adding if quotas are ever switched on with finite limits; not worth blocking a change that ships at `-1`.

**SUGGESTION**

1. `TenantQuotaUsage`'s class docblock still says rows are *"never read without the lock"*, but `checkConsumption()` (`:124`) and `summary()` (`:325`) both read the model unlocked. Correct the docblock.
2. `ReservationService::create` still carries the same 5-line `trial-usage-quotas` comment block **twice** (`:123-127` and `:128-132`) — verbatim copy/paste duplication, re-confirmed this session.
3. `checkConsumption()`, `assertCanRegisterUnits()` and `summary()` each call `Tenant::findOrFail(tenant('id'))`, the one remaining non-fail-open branch in a deliberately fail-open guard.
4. `scope='active'` rows are supported by resolution but never seeded — as designed for v1; worth an admin-facing note when quotas are turned on.
5. Batch pre-flights (`UnitController`, `ImportRunner`) still validate the band only; the trial unit quota is enforced per row inside the batch transaction (accepted v1 nuance, D5).
6. `consume()` remains `public` with no tenancy guard and a nullable `$check`. Making it `private` would close the CRITICAL-2 hazard class permanently — but two tests now call it directly, so that change has a test cost.
7. Convert `tasks.md`'s `### [x]` headings to `- [x]` list items so the native dispatcher stops reporting "no markdown task checkboxes". Cosmetic.
8. Commit `0f0e4f1` mixes this change with unrelated work (Setting JSON re-encode, `ConfigurationController`, billing catalog verify command). Noted for traceability.
9. **New, outside this change's scope**: file the QR expiry timezone bug from WARNING-1. `AccessService::resolveExpiry` builds the expiry in the tenant timezone, but the `datetime` cast reads it back in `config('app.timezone')`; when the app timezone is ahead of the tenant's, a `SINGLE_ENTRY` QR is expired the moment it is created. The same shape affects `TIME_BASED`'s default `addHours(24)` less visibly.

### Verdict

**PASS WITH WARNINGS** — 0 CRITICAL, 6 WARNING, 9 SUGGESTION. **24/24 scenarios, 20/20 requirements, 28/28 tasks.**

**What closed.** REQ-QV-02's frontend half now has runtime coverage whose green depends on `formatApiErrorMessage` actually passing the backend's message through, and I verified independently that the two flows the requirement is about consume that exact function. `assertAndConsume()`'s tenancy guard is pinned by a test that seeds the counter **at** its limit so only the guard can let it through, and that fails on the counter-movement assertion if the guard is deleted. Both closures cost **zero production bytes** — `git diff` is empty on `QuotaService.php` and `apiClient.ts` — so nothing verify #2 and #3 confirmed correct was disturbed, and every regression floor except the Access batch is met exactly or beaten.

**What I am not calling clean.** A named regression floor, `--filter=Access`, is numerically below its baseline (92/2 against 93/1). I chose not to file that as a blocker because I proved the extra failure is a wall-clock artifact of a two-month-old timezone bug on a code path with no quota call, in a file this change never touched — with a prediction anyone can falsify by re-running the suite before 18:00 local. And one of the four new frontend tests is a tautology whose comment claims a property the code does not have; it should be deleted or made real, but it is not load-bearing for any scenario.

**Recommendation: proceed to `sdd-archive`.** The tasks-format message is a parser artifact and must not gate it.
