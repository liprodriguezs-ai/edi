# Tasks: Unit Limit Enforcement

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 550–750 |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single PR, `size:exception` |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|-----------------------|------------------|--------------------|
| 1 | Backend core: `UnitLimitCheck` DTO + `UnitLimitExceededException` + `UnitLimitService` (band resolution, checks, copy) | PR 1 (single, size:exception) | `php artisan test --filter=UnitLimitServiceTest` (host php, alone) | N/A — pure service, no route yet; proven by unit test | Revert `Modules/SubscriptionPlan/app/Services/UnitLimitService.php`, `UnitLimitCheck.php`, `Modules/SubscriptionPlan/app/Exceptions/UnitLimitExceededException.php` — no schema/migration |
| 2 | Backend wiring: `UnitObserver::creating/restoring` + `UnitController` batch pre-flights + `ImportRunner::validate()` block | PR 1 (same) | `php artisan test --filter=UnitLimitEnforcementTest` and `--filter=ImportUnitLimitTest` (host php, alone) | Manual QA: `admin@encinos.dev`/`secret1234` at `http://192.168.100.136:5173/`, import 20+ new units for "Privada Los Encinos (Dev)" (Básico, 6/25) | Revert `UnitObserver.php`, `UnitController.php`, `ImportRunner.php` — the guard is additive; existing behavior resumes untouched |
| 3 | Backend tests + full regression | PR 1 (same) | `php artisan test Modules/DataImport/tests` + Housing suite (host php, ONE process) | Same as above | N/A — test-only files |
| 4 | Frontend: `ValidateStep.tsx` row-0 "Archivo" | PR 1 (same) | `pnpm test -- ValidateStep` + `pnpm typecheck` | Manual QA: validate step showing a file-level blocking issue | Revert `ValidateStep.tsx` + its test — independent of backend units |

## Phase 1: Foundation — DTO & Exception (`Modules/SubscriptionPlan`)

- [x] 1.1 Create `backend/Modules/SubscriptionPlan/app/Services/UnitLimitCheck.php` — readonly DTO returned by `checkIncrease()`: `allowed: bool`, `planName: ?string`, `unitLimit: ?int`, `activeUnits: int`, `attemptedIncrease: int`, `suggestedPlanName: ?string`. Backs REQ-08/REQ-09 (message needs all four numbers). No prior test to satisfy — used by 1.2 and 2.1.
- [x] 1.2 Create `backend/Modules/SubscriptionPlan/app/Exceptions/UnitLimitExceededException.php` — `extends Exception implements ShouldntReport`; named constructor `forIncrease(UnitLimitCheck $check): self`; `render(): JsonResponse` returns 422 with `{message, code: 'UNIT_LIMIT_EXCEEDED', plan_name, unit_limit, active_units, attempted_increase, suggested_plan_name}`. Model directly on `Modules/Collections/app/Exceptions/DelinquencyRestrictedException.php` (private payload + self-render + `ShouldntReport`). Message text uses the two API-variant templates from design's Interfaces/Contracts section (upgrade-exists vs top-band — `suggested_plan_name === null`). Satisfies REQ-08, REQ-09, Decision 3. `ShouldntReport` is MANDATORY (routine business block, not an application error).

## Phase 2: `UnitLimitService` (`Modules/SubscriptionPlan`)

- [x] 2.1 Create `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php`:
  - `resolveBand(): ?array{planName: string, limit: ?int}` — `TenantEntitlement::active()->with('billing_package')->get()`, group by `billing_package_id`. 0 distinct or null `suggested_unit_max` → return `null` (fail-open, Decision 4). >1 distinct → pick the row with `max(suggested_unit_max)` AND call `report(new \RuntimeException('Tenant has multiple distinct billing packages across active entitlements'))` (observability only, never throws).
  - `activeUnitCount(): int` — `Unit::count()` (TenantScope + default `deleted_at IS NULL` scope = exactly `unit_identity_unique`'s liveness rule, REQ-06).
  - `checkIncrease(int $delta): UnitLimitCheck` — early-return `allowed: true` when `! tenancy()->initialized` (Decision 7) or `resolveBand()` is `null` (fail-open). Otherwise `activeUnits + delta > limit` decides `allowed`; `suggestedPlanName` = `PlanPricingService::planForUnits(activeUnits + delta)->name` only when that plan fits the needed total AND differs from the current plan, else `null` (Decision 6 — `planForUnits` clamps above Premium, so the fits-check alone prevents a dead-end link, REQ-08).
  - `assertCanIncrease(int $delta): void` — `throw UnitLimitExceededException::forIncrease($this->checkIncrease($delta))` when not allowed.
  - `buildImportPreviewMessage(UnitLimitCheck $check): string` — the two Preview-variant templates (upgrade-exists vs top-band) from design's Interfaces/Contracts section, for Phase 5.
  Satisfies REQ-01–REQ-04, REQ-06, REQ-08, Decisions 4, 6, 7.
- [x] 2.2 Same file — add a class-level docblock sentence recording the super-admin decision explicitly: *"No super-admin exemption: this guard keys on `tenancy()->initialized`, NOT `auth()`/role, so a super admin acting inside a tenant is subject to the same cap. The ONE practical exception is a tenant with no active entitlement (fail-open, see `resolveBand()`) — `SubscriptionStateService` (`:53-54`) already marks super-admin/lawyer sessions `exempt`+`is_blocked=false` there, so that combination reaches the fail-open branch like any other actor. This is intentional (Decision 4), not an oversight."* Satisfies the spec's Open Question on super-admin bypass.

## Phase 3: `UnitObserver` guard (`Modules/Housing`)

- [x] 3.1 `backend/Modules/Housing/app/Observers/UnitObserver.php` — add `creating(Unit $unit): void` calling `app(UnitLimitService::class)->assertCanIncrease(1)`, placed before the existing `created()` method. Satisfies REQ-01, REQ-07 (in-place updates never fire `creating`), Decision 1.
- [x] 3.2 Same file — add `restoring(Unit $unit): void` calling `assertCanIncrease(1)` ONLY when `$unit->trashed()` is true. `restoring` fires BEFORE `deleted_at` is cleared (`SoftDeletes::restore():170-172`), so `trashed()` still reflects the pre-restore state at call time; skip entirely when false so a live unit id passed through `buildBatchRestoreFetchQuery()`'s `withTrashed()` (not `onlyTrashed()`, `:465`) never wrongly consumes a band slot. Satisfies REQ-03, REQ-06.

## Phase 4: `UnitController` batch pre-flights (`Modules/Housing`)

- [x] 4.1 `backend/Modules/Housing/app/Http/Controllers/UnitController.php` — add `protected function beforeBatchStore(Request $request)`: `app(UnitLimitService::class)->assertCanIncrease(count($this->retrieve($request, 'resources', [])))`. Runs BEFORE any row is inserted (Orion wraps `batchStore` in one transaction via `config('orion.transactions.enabled') === true`, verified in `config/orion.php:33`, so the per-row observer backstop would already roll back atomically — this pre-flight exists for an accurate all-or-nothing error, not to create atomicity). Satisfies REQ-02, Decision 2.
- [x] 4.2 Same file — add `protected function beforeBatchRestore(Request $request)`: resolve requested keys via `$this->resolveResourceKeys($request)`, count how many are CURRENTLY trashed (`Unit::onlyTrashed()->whereIn('id', $keys)->count()`), then `assertCanIncrease($trashedCount)` (not `count($keys)` — a caller mixing live + trashed ids must only be charged for the trashed ones, consistent with 3.2's guard). Satisfies REQ-04, Decision 2.

## Phase 5: `ImportRunner` preview integration (`Modules/DataImport`)

- [x] 5.1 `backend/Modules/DataImport/app/Services/ImportRunner.php` — inject `UnitLimitService` via constructor (alongside `RowImporter`). In `validate()`, insert AFTER `$counters = $this->calculateDistinctCounters($validRowIdentities);` (currently line 81) and BEFORE the `return [...]` block that calls `$this->collapseGeneralIssues($allIssues)` (currently line 89): call `$check = $this->unitLimitService->checkIncrease($counters['units_to_create']);` and, when `! $check->allowed`, `array_push($allIssues, ['row' => 0, 'severity' => 'error', 'column' => 'unidades', 'general' => true, 'message' => $this->unitLimitService->buildImportPreviewMessage($check)]); $blockedCount++;` — **pinned append point**: this joins `$allIssues` BEFORE it is consumed by `collapseGeneralIssues()`, so the file-level error passes through untouched (verified: the collapse function only inspects `severity === 'error'` at `:117-121` and returns errors in original order, unmodified). Do NOT merge into the collapse result instead — the append-before approach is the one to implement. Satisfies REQ-05, REQ-10, Decision 5.
- [x] 5.2 Same file — update the `validate()` `@return` docblock (no new top-level keys; `row: 0` is a valid existing `row` value, already typed `int`). Verify zero new queries introduced beyond `checkIncrease()`'s one band-resolution + one count query (REQ-10 regression guard — no per-row queries).

## Phase 6: Backend Tests

- [x] 6.1 Create `backend/Modules/SubscriptionPlan/tests/Unit/UnitLimitServiceTest.php` — band resolution: 0 / 1 / >1 distinct `billing_package_id` (assert `report()` fires via `Log`/exception spy, never throws), expired entitlements excluded, null `suggested_unit_max` → fail-open; `checkIncrease()` math at/over/under the limit; `suggestedPlanName` present when a fitting next band exists, `null` at Premium. Satisfies REQ-06, REQ-08, Decision 4.
- [x] 6.2 Create `backend/Modules/Housing/tests/Feature/UnitLimitEnforcementTest.php` — HTTP tests on `POST /unit`, `POST /unit/batch`, `POST /unit/{id}/restore`, `POST /unit/batch/restore` with a seeded Básico band (limit 25): exact-cap create/restore succeeds; cap+1 → 422 with plan/limit/count/attempted-increase in tú-form Spanish; batch over limit admits ZERO (count unchanged, not partial); batch exactly-filling succeeds; batch/restore mixing a live id + trashed ids only charges the trashed ones (pins the `withTrashed()` gap from 3.2/4.2); soft-deleted units excluded from count; no active entitlement → fail-open (create succeeds); Premium at cap → message has no upgrade link. Satisfies REQ-01–REQ-04, REQ-06–REQ-09.
- [x] 6.3 Same file — add one test asserting batch atomicity is PRESERVED specifically because `config('orion.transactions.enabled')` is `true` (assert the config value directly, then assert a mid-batch guard throw on row N leaves ZERO of that batch's rows persisted). Comment the test to say atomicity breaks silently if this config ever flips. Satisfies the validator's config-pinning ask.
- [x] 6.4 Create `backend/Modules/DataImport/tests/Feature/ImportUnitLimitTest.php` — over-band file → `POST /import/validate` returns 422, error key `rows.0.unidades`, `blocked > 0`, `units_to_create`/`residents_to_create`/`payments_to_register` unchanged; within-band file → 200, no unit-limit issue present; assert zero extra per-row queries via `DB::connection()->enableQueryLog()` + `getQueryLog()` comparing a 5-row and a 500-row fixture (REQ-10 regression guard, existing pattern from `InstrumentationTest.php`); confirm `/import/execute` and chunk-job behavior are untouched (REQ-11 — run only as a smoke check, no new assertions on execute internals). Satisfies REQ-05, REQ-10, REQ-11.
- [x] 6.5 Run the full regression gate: `pgrep -f "artisan test"` first (ONE process only), then `php artisan test Modules/DataImport/tests` (host php) confirming baseline 72 Unit + 47 Feature / 455 assertions still pass plus new tests, and the Housing unit/feature suite. Run `vendor/bin/pint --dirty` scoped ONLY to `Modules/SubscriptionPlan`, `Modules/Housing`, `Modules/DataImport` paths touched by this change (never repo-wide — a parallel session has uncommitted work in `Modules/Collections`/`Modules/Configuration`).

## Phase 7: Frontend — Validate step file-level rendering

- [x] 7.1 `frontend/src/modules/import/components/ValidateStep.tsx` (Fila cell, currently lines 156–158) — check `issue.row === 0` FIRST and render `'Archivo'`; otherwise keep the existing `issue.count > 1 ? 'N filas' : issue.row` logic. No `schema.ts` change needed (`IIssueRow.row` is already `number` and already covers `0`). Satisfies REQ-05 UX, Decision 5.
- [x] 7.2 Update `frontend/src/modules/import/__tests__/ValidateStep.test.tsx` — add a fixture with `row: 0` asserting the cell renders `"Archivo"`; confirm existing grouped (`count`) and ungrouped (plain row number) fixtures are unaffected. Run: `pnpm test -- ValidateStep`.

## Phase 8: Full Regression Gate

- [x] 8.1 Backend: `php artisan test Modules/DataImport/tests` (host php, alone) + Housing suite + `vendor/bin/pint --dirty` (scoped, see 6.5). Confirms REQ-10, REQ-11.
- [x] 8.2 Frontend: `pnpm test`, `pnpm typecheck`, `pnpm lint`, scoped to `src/modules/import` for an attributable signal (the pre-existing `DelinquencyConfigSection.test.tsx` failure from the parallel session is unrelated and out of scope).
- [x] 8.3 Manual QA at `http://192.168.100.136:5173/`. **DONE 2026-07-15 by the orchestrator**, logged in as `admin@encinos.dev` on "Privada Los Encinos (Dev)" (Básico, limit 25, 6 active → 19 free). Results, observed on screen:
  - **Over-band file BLOCKS**: 30 distinct new units → one file-level error rendering as `Archivo | unidades | "Tu plan Básico permite hasta 25 unidades activas y ya tienes 6; este archivo crearía 30 unidades nuevas. Reduce el archivo o cambia al plan Inicial."` — all four numbers present, the next fitting band named, and **"Continuar" disabled**. The Fila cell shows "Archivo", not a literal "0".
  - **Within-band file PASSES**: 10 distinct new units → "Sin errores. El archivo está listo para importar.", zero errors, **"Continuar" enabled**. Confirms the guard discriminates rather than blocking indiscriminately.
  - Not exercised on screen: `POST /unit` / batch / restore via the Housing UI — covered by `UnitLimitEnforcementTest` (13/13, 52 assertions), which drives all five paths through their real HTTP routes.
