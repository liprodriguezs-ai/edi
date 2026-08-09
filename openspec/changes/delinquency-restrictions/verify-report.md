# Verification Report — delinquency-restrictions

- **Change:** `delinquency-restrictions`
- **Project:** `residia-monorepo` (Kivo7)
- **Mode:** Standard (strict_tdd: false) · artifact store: hybrid
- **Date:** 2026-07-14
- **Artifacts read:** spec (R-DELQ-1..11), design.md (rev 2), tasks.md, Engram `sdd/delinquency-restrictions/apply-progress` (id 1566)
- **Verdict:** **PASS WITH WARNINGS** — 0 CRITICAL · 2 WARNING · 3 SUGGESTION

## Method

Verification was done by reading production code, tests, and `git diff`/`git status`.
Backend suites were NOT re-run: the team lead had already executed
`php artisan test --filter=Delinquency` → **51 passed / 180 assertions** against an
exclusive Postgres `testing` DB, and this repo shares one `testing` DB whose
`RefreshDatabase` `migrate:fresh` corrupts concurrent runs. Frontend: `pnpm test`
→ **630 passed / 82 files** (lead-run). `vendor/bin/pint --test` was re-run here
scoped to this change's files (safe, no DB) → **passed**.

## Completeness

| Phase | Tasks | Verified |
|---|---|---|
| 1 Foundation | 1.1–1.7 `[x]` | All true |
| 2 Persistence | 2.1–2.3 `[x]` | All true |
| 3 Config API | 3.1–3.3 `[x]` | All true |
| 4 Hooks | 4.1–4.12 `[x]` | All true |
| 5 Resident endpoint | 5.1–5.3 `[x]` | All true |
| 6 Guard endpoint | 6.1–6.3 `[x]` | All true |
| 7 FE config | 7.1–7.3 `[x]` | All true |
| 8 FE resident | 8.1–8.4 `[x]` | All true |
| 9 FE guard | 9.1–9.3 `[x]` | All true |
| 10 Gates | 10.1–10.4 `[ ]` | 10.1/10.2/10.3 done but unticked; **10.4 not run** |

**No task ticked `[x]` is untrue.** No claim/code drift found across Phases 1–9.

## Spec compliance matrix

| Req | Status | Implementation evidence | Test evidence |
|---|---|---|---|
| R-DELQ-1 criterion + master switch | PASS | `DelinquencyRestrictionService.php:196` (master switch), `:204-207` (OVERDUE-only equality vs the central-catalog UUID). No `[OVERDUE, PARTIAL]` filter anywhere in new code (grep-verified). | `DelinquencyRestrictionServiceTest.php:165` (master off), `:271` PARTIAL + 9,000,000 unpaid remainder → NOT blocked |
| R-DELQ-2 threshold via `effectiveExpected()` | PASS | `:231-248` `overdueBalancesByUnit()` sums `max(0, effectiveExpected − paid)`; no 5th copy of the formula | `:203` (499,999 → no), `:219` (500,000 → yes), `:235` (500,001 → yes), `:251` (min=0, balance 1 → yes) |
| R-DELQ-3 per-feature toggles | PASS | `:200` + `blockSlugFor():265-273` | `:181` amenities off → allowed, visitor_access on → blocked |
| R-DELQ-4 blocked actions (5 hooks) | PASS | `ReservationService.php:114`, `AccessService.php:150` (first stmt of `generateQr`, L145), `VisitorController.php:221`/`:244` (after `authorizeUnitMembership`), `PackageService.php:161` (first stmt in `DB::transaction`), `:201-208` (pre-transaction batch guard) | `AmenityReservationDelinquencyGuardTest:160`; `AccessDelinquencyGuardTest:144`, `:174`; `PackageDelinquencyGuardTest:187`, `:229` |
| R-DELQ-5 never-blocked | PASS | `AccessLogController` **not in the diff at all**; `VisitorController::cancelQr` (L174), `PackageService::handover`, `PackageController::verifyCode`, `AmenityController` all untouched (diff hunks confined to constructor + `register`/`batchRegister` / `approve`/`reject` / `generateQr`) | `AccessDelinquencyGuardTest:219` (my-qr/my-log/cancel 200); `PackageDelinquencyGuardTest:264` (handover + verify-code 200 with real pickup code); `AmenityReservationDelinquencyGuardTest:191` (browse 200) |
| R-DELQ-6 audience split + privacy | PASS | `DelinquencyRestrictedException.php:44-54` — `forGuard()` takes **no money argument**, message is the private constant `GUARD_MESSAGE`; privacy is a type-level property, not a call-site discipline. No other path leaks: generic handler never sees an amount (guard exception message carries none), `restricted-units` returns ids only. | `DelinquencyRestrictionServiceTest:321` (no `overdue_balance`/`notice_text` keys, no "adeudo"/"moroso"/"deuda" in raw JSON); `PackageDelinquencyGuardTest:187` (same assertions over the real HTTP 403) |
| R-DELQ-7 restricted-units listing | PASS | `PackageController.php:142-151` — `authorize('create.package')`, `{unit_ids}` only. Route `Modules/Package/routes/api.php:26` registered **before** `Orion::resource('package')` at `:44`. Not in `/selects`. | `PackageDelinquencyGuardTest:304` (`assertExactJson(['unit_ids' => [...]])` — ids only, exact), `:326` (resident → 403) |
| R-DELQ-8 settings storage + endpoints | PASS (see S-1) | `DelinquencySettings.php` (6 slugs); `ConfigurationController.php:1813` GET / `:1852` PUT gated `read/update.configuration`; **no migrations, no new tables, no new permissions** (git-verified) | `DelinquencySettingsTest:75` (exactly 6 rows), `:199` (500,000 stored + returned as TenantMoney), `:224`/`:236` (422s), `:158`/`:248` (security role → 403) |
| R-DELQ-9 immediate unblock | PASS | No new code needed — payment application recomputes `unit.charge_status_id` in-transaction | `PackageDelinquencyGuardTest:342` — 403 → `PaymentService::register` → **201 immediately after commit** |
| R-DELQ-10 tenant isolation | PASS | `Setting`/`Unit`/`Charge` all tenant-scoped via `BelongsToTenant`; service never crosses tenants | `AmenityReservationDelinquencyGuardTest:245` (A's config never restricts B), `DelinquencySettingsTest:126` (GET isolated) |
| R-DELQ-11 UI never hides/disables | PASS | `VisitorListPage.tsx` and `AmenityBrowseDetailPage.tsx` are **not in the diff** — entry points untouched by construction. Notice lives inside the wizard (`ReserveAmenityWizard.tsx:337-353`) and modal (`VisitorFormModal.tsx:190`). | `ReserveAmenityWizard.test.tsx:114` (always reaches picker, both units selectable, nothing hidden/disabled); `VisitorFormModal.test.tsx:103`, `:109`/`:120`/`:134` `not.toBeDisabled()` |

**Every requirement has passing covering tests. No spec requirement is untested.**

## Focused checks from the verification brief

| # | Check | Result |
|---|---|---|
| 1 | `PARTIAL` must not block | **CONFIRMED.** Only `ChargeStatus::OVERDUE` appears in the rule; pinned by `:271`. |
| 2 | Never-blocked untouched + 200-tested | **CONFIRMED.** `AccessLogController` absent from the diff entirely. |
| 3 | Guard payload privacy, no alternate leak path | **CONFIRMED.** `forGuard()` cannot carry money (no parameter). |
| 4 | `restricted-units`: ids only, gated, route order | **CONFIRMED.** Line 26 precedes line 44. |
| 5 | Per-unit state, never ANY-aggregate (the bug a gate caught once) | **CONFIRMED both layers.** `stateForUnits():119-145` maps per unit; FE `useMyRestrictions.ts:47-51` `unitState(unitId)` + `ReserveAmenityWizard.tsx:222` / `VisitorFormModal.tsx:82` gate on the SELECTED unit. Multi-unit A-current/B-overdue precision tested at `DelinquencyRestrictionServiceTest:358`, `DelinquencyRestrictionControllerTest:105`, `ReserveAmenityWizard.test.tsx:126`, `VisitorFormModal.test.tsx:112` (incl. switching back to A lifting the block). |
| 6 | "Reservar"/"Registrar visita" visible, not disabled | **CONFIRMED.** Entry-point files unmodified. (The modal's *submit* button shares the label "Registrar visita" and IS disabled on a restricted selection — that is design §8.2's "submit is stopped only for that selection", not the entry point.) |
| 7 | Octane `scoped()` not `singleton()` | **CONFIRMED.** `CollectionsServiceProvider.php:56` `$this->app->scoped(DelinquencyRestrictionService::class)`. The only `singleton()` in the file is pre-existing `PaymentEarmarkRegistry` (L50). |
| 8 | Money: INTEGER minor units, TenantMoney on wire, 5000→500000 | **CONFIRMED** both directions (`DelinquencySettingsTest:199` backend, `DelinquencyConfigSection.test.tsx:65` frontend). |
| 9 | No migrations / tables / permissions | **CONFIRMED** via `git status` + diffs of `permissions.csv` / `modules.csv` / `RolePermissionTableSeeder.php` (zero delinquency hits). |
| 10 | Five hooks match design §5; batch guard in service (ADR-6) | **CONFIRMED.** `PackageController::batchRegister` only delegates; guard is in `PackageService::batchRegister():201-208`, pre-transaction. |

## Issues

### CRITICAL
None.

### WARNING

**W-1 — `settings()` swallows `QueryException` and fails OPEN (undesigned deviation).**
`DelinquencyRestrictionService.php:291-296` wraps the settings read in
`try { … } catch (QueryException) { return $this->settingsCache = $defaults; }`.
Design §3 specifies one `whereIn` + memoization and no such catch. Because every
default ships disabled, any `QueryException` on that read silently lifts **all**
restrictions tenant-wide instead of surfacing an error. The inline justification
("an isolated unit-test context") does not hold — every delinquency test is a
Feature test with `RefreshDatabase` and a real `setting` table, so the catch is
effectively dead code that only masks production faults. Recommend removing it.
Not spec-breaking (no requirement mandates fail-closed), hence WARNING not CRITICAL.

**W-2 — Phase 10 gate tasks 10.1–10.4 unchecked; 10.4 genuinely not run.**
10.1 (`pint`) re-verified here → passed for every file this change touches.
10.2/10.3 were run by the lead (51 backend / 630 frontend, all green) but remain
unticked in `tasks.md`. **10.4 (`/review-stack` + `@infra-cost-reviewer`) has not
been run** — that is a real outstanding gate before archive, not bookkeeping.

### SUGGESTION

**S-1 — `min_overdue_amount` can persist as `TYPE_STRING`, contradicting R-DELQ-8's "MUST use `Setting::TYPE_INTEGER`".**
`ConfigurationController.php:1867` validates `['sometimes','integer','min:0']`, which
Laravel accepts for a numeric *string* ("500000"). The type switch at `:1885` then
uses `is_int($value)`, which is false for that string → the row stores `TYPE_STRING`.
Functionally harmless (the service at `:210` and the GET at `:1834` both `(int)`-cast)
and it is a faithful clone of the pre-existing `updatePackageSettings` pattern
(`:1769`), and the frontend always sends a number. Fix if desired:
`$request->integer('min_overdue_amount')` or cast before the switch.

**S-2 — `effectiveExpected()`'s discount/surcharge branches are never exercised.**
Every test charge sets only `expected_amount`/`paid_amount`. R-DELQ-2's "computed via
`effectiveExpected()`" is structurally satisfied (verified by reading `:244`), but no
test asserts a balance on a charge carrying `discount_amount`/`surcharge_amount`. One
such case would pin the reuse against a future regression.

**S-3 — Cosmetic edge case: OVERDUE unit with zero OVERDUE charges + `min = 0`.**
`stateForUnits():125` treats `min === 0` as over-threshold without a balance check, so
such a unit is reported restricted with `overdue_balance: 0` → the resident notice reads
"Adeudo vencido: $0.00". `unit.charge_status_id` is derived from charges, so this should
not occur in practice.

## Design coherence

| Design element | Coherent |
|---|---|
| §2 six slugs + defaults (all disabled) | Yes |
| §3 service shape, batch ≤3 queries, `unit_id` in select as groupBy key | Yes (`:241`) |
| §3/ADR-4 `scoped()` binding | Yes |
| §4/ADR-2 exception owns the audience split | Yes |
| §5 five hooks, exact insertion points | Yes |
| §6.1/6.2/6.3 endpoints, gates, route order | Yes |
| §7 seeder `withTrashed()` create/restore (NOT `firstOrCreate`) | Yes (`CollectionsDatabaseSeeder.php:46-61`) |
| §8 FE: lazy fetch on existing `enableUnits()` trigger; per-unit gating | Yes (`usePackageCatalogOptions.ts:52-55`, tested `:37`/`:42`) |
| §9 no migrations / tables / permissions | Yes |
| §3 `settings()` (no try/catch specified) | **Deviation — see W-1** |

## Scope creep

**None attributable to this change.** Other dirty paths in the working tree
(`Modules/DataImport/**`, `database/seeders/csv/module_system.csv`,
`frontend/src/modules/{charge,payment,import}/**`) are interleaved WIP from
parallel sessions on this shared checkout, not part of `delinquency-restrictions`.

## Final verdict

**PASS WITH WARNINGS.** All eleven requirements are implemented as specified and
covered by passing tests. The traps flagged in the brief — a `PARTIAL` copy, an
ANY-aggregate, a guard-side amount leak, `singleton()` under Octane, a swallowed
`restricted-units` route, disabled entry-point buttons — are each demonstrably
absent, and several are prevented structurally rather than by convention. Nothing
blocks archive except running gate 10.4 (`/review-stack` + `@infra-cost-reviewer`).
Recommend also resolving W-1 (fail-open catch) before merge.
