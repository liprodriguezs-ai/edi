# Tasks: import-bulk-performance

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 700–950 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (backend infra + jobs) → PR 2 (frontend) → PR 3 (tests) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Backend: migration + model constants + SilencesInstrumentation trait + RunImportJob rewrite + 2 new jobs + controller status patch | PR 1 | Base = main; includes ImportRunner::execute() retirement |
| 2 | Frontend: schema.ts types + ConfirmStep startedRef + phase labels + completed_with_errors amber banner | PR 2 | Base = PR 1 branch |
| 3 | Tests: all 6 spec scenarios + cross-tenant isolation | PR 3 | Base = PR 2 branch; gating before merge to main |

---

## Phase 1: Foundation / Infrastructure

- [x] 1.1 Create ALTER migration `2026_06_14_XXXXXX_add_phase_to_import_run_table.php` in `Modules/DataImport/database/migrations/` — add nullable `string('phase')->after('status')->comment(...)` with a proper `down()` that calls `dropColumn`.
- [x] 1.2 Update `Modules/DataImport/app/Models/ImportRun.php` — add `STATUS_COMPLETED_WITH_ERRORS`, all `PHASE_*` constants, add `'phase'` to `$fillable`, add static `emptyReport(): array` with all accumulator keys (`bank_deltas`, `affected_unit_ids`, `failed_count`, `succeeded_count`, `payments_skipped`, `payments_registered`, etc.).
- [x] 1.3 Create `Modules/DataImport/app/Jobs/Concerns/SilencesInstrumentation.php` trait — `disableQueryLog()`, `class_exists`-guarded `Telescope::stopRecording()` (restored in finally). R1 RESOLVED: installed package is `overtrue/laravel-query-logger` (config `logging.query.*`), NOT `antoniors/laravel-sql-logger`. Off-switch sets `logging.query.except => ['*']` + `slower_than => PHP_INT_MAX` (re-read per query by the boot-registered listener), originals restored in finally.

## Phase 2: Core Backend Jobs

- [x] 2.1 Rewrite `Modules/DataImport/app/Jobs/RunImportJob.php` as coordinator: call `silenceInstrumentation()` first in a `try/finally` that restores instrumentation, set `status=processing, phase=reading_file`, parse workbook ONCE via `WorkbookReader::parse()`, persist normalized rows (`{_row, ...fields}` list) to `imports/{tenantId}/{importRunId}.rows.json`, set `progress.total` + `phase=processing` on the run, build chunk descriptors (CHUNK_SIZE=200), dispatch `Bus::batch([...ProcessImportChunkJob × K])->allowFailures()->onQueue('imports')->finally(fn() => FinalizeImportJob::dispatch($importRunId))->dispatch()`. Own catch sets `status=failed` if coordinator throws before batch. Empty file → dispatch finalizer directly. Ctor now takes `string $importRunId` (not the model).
- [x] 2.2 Create `Modules/DataImport/app/Jobs/ProcessImportChunkJob.php` — implements `ShouldQueue`, uses `Batchable` + `SilencesInstrumentation`, `$tries=1`, `$timeout=300`; `handle()`: silence (with `finally` restore), batch-cancelled early return, `ImportRun::find($importRunId)`, load `rows.json`, `array_slice($offset, $length)`, lightweight defensive guard (REQ-08) → `failed`, loop rows calling `RowImporter::execute()` in per-row `DB::transaction`, catching `Throwable` per row (increment `failed_count`, append issue); accumulate counters (registered, skipped, bank_deltas, affected_unit_ids); then locked RMW on `import_run` to persist counters + issues additively (design §2.4).
- [x] 2.3 Create `Modules/DataImport/app/Jobs/FinalizeImportJob.php` — `ShouldQueue`, `SilencesInstrumentation`, `$tries=1`; `handle()`: silence + restore, `ImportRun::find`, skip if already `failed`, set `phase=finalizing`, recompute `ChargeStatusService::recomputeUnitAggregates($affectedUnitIds)` ONCE in `DB::transaction`, compute terminal status (`ready` / `completed_with_errors` / `failed`) from accumulated counters, resolve `bank_balance_delta` from `bank_deltas`, set `succeeded_count`, strip interim keys, set `phase=done` + `processed=total`, persist final report + status under `lockForUpdate()`, delete `rows.json` + upload file (best-effort, logged).
- [x] 2.4 Retire `ImportRunner::execute()` in `Modules/DataImport/app/Services/ImportRunner.php` — removed method + now-dead `buildBankBalanceDelta` + unused imports; `validate()` and its whole call chain unchanged.
- [x] 2.5 Update `Modules/DataImport/app/Http/Controllers/ImportController.php` `status()` action — added `'phase' => $run->phase` and `'error' => $run->error` (additive). Also `executeImport` now seeds `phase`/`progress.phase` and dispatches the coordinator with `$run->id` on the `imports` queue.

## Phase 3: Frontend

- [x] 3.1 Update `frontend/src/modules/import/schema.ts` — add `ImportPhase` type union, expand `ImportRunStatus` to include `'completed_with_errors'`, add `phase?: ImportPhase` to `IImportProgress` and `IImportRun`, add `failed_count: number` and `succeeded_count: number` to `IImportReport`. No `any` allowed. Validate existing callers still compile.
- [x] 3.2 Update `frontend/src/modules/import/components/ConfirmStep.tsx` (or wherever the execute effect lives) — add `const startedRef = useRef(false)` scoped to the component instance; add `if (startedRef.current) return; startedRef.current = true` as the FIRST statement inside the effect body, BEFORE `POST /execute`. Do NOT reset `startedRef` in the cleanup. Keep existing `cancelled` flag for poller cleanup.
- [x] 3.3 In `ConfirmStep.tsx` — add `const PHASE_LABELS: Record<ImportPhase, string>` lookup object; render the appropriate label during polling; while `phase === 'processing'` show `Procesando ${processed} / ${total} filas…`; while `phase === 'reading_file'` and `total === 0` show indeterminate spinner; wire to visible UI during non-terminal states.
- [x] 3.4 In `ConfirmStep.tsx` — add `'done_with_errors'` to `ExecutionState` (or equivalent state enum/union); update poller to map `status === 'completed_with_errors'` to `done_with_errors` state (calls `onComplete(report)` so report step renders); add amber banner (`border-amber-200 bg-amber-50 text-amber-800`) for `done_with_errors` state, displaying `succeeded_count`, `skipped_count`, and `failed_count`; ensure per-row `report.rows` error list is scrollable when `failed_count > 0`.

## Phase 4: Tests

- [x] 4.1 `Modules/DataImport/tests/Feature/RunImportJobTest.php::test_scenario_01_multi_chunk_happy_path_completes_end_to_end` — SCENARIO-01. Runs CHUNK_SIZE+5 rows (>1 chunk) end-to-end under sync (Bus::batch + ->finally run INLINE), asserts `status=ready`, `phase=done`, `succeeded_count==count`, `failed_count=0`, `progress.processed==total`, one `payment` per row with `R-YYYY-NNNNNN`, units committed. (1000 rows reduced to CHUNK_SIZE+5 for deterministic speed — same multi-chunk path.)
- [x] 4.2 `Modules/DataImport/tests/Feature/RunImportJobTest.php::test_scenario_02_dispatches_one_chunk_job_per_slice` + `::test_scenario_02_progress_is_persisted_per_chunk_not_per_row` — SCENARIO-02. Real CHUNK_SIZE=200 (not 100): 500 rows → ceil(500/200)=3 `ProcessImportChunkJob` (asserted via `Bus::fake` + running the coordinator handle directly). Progress test counts `UPDATE ... import_run` statements via `DB::listen` on a 2-chunk run, asserts ≤10 (kills the old per-row defect).
- [x] 4.3 `Modules/DataImport/tests/Feature/RunImportJobTest.php::test_scenario_03_partial_failure_ends_completed_with_errors` — SCENARIO-03. 3 good + 2 guard-failing rows → `status=completed_with_errors`, `phase=done`, `failed_count=2`, per-row errors in `report.rows`, `succeeded + payments_skipped + failed == total == processed`, good rows still committed. (Stored skip counter is `payments_skipped`, not the spec's `skipped_count` — FE maps it; see risks.)
- [x] 4.4 `Modules/DataImport/tests/Feature/RunImportJobTest.php::test_scenario_04_reimport_same_file_skips_all_rows` — SCENARIO-04. Re-import same content → `status=ready`, `succeeded_count=0`, `payments_skipped=3`, `failed_count=0`, zero new `payment`/`payment_application`, `bank_account.current_balance` (raw minor units) unchanged.
- [x] 4.5 `Modules/DataImport/tests/Feature/CrossTenantIsolationTest.php` (new) — **SCENARIO-05 (HIGH RISK)**: 4 tests. (1) end-to-end import as tenant A → ZERO payments/units/import_run under tenant B (asserted with `withoutGlobalScope(TenantScope)` so a leak is visible). (2) coordinator dispatches a `Bus::batch` of `ProcessImportChunkJob` while tenant A is active. (3) `QueueTenancyBootstrapper::getPayload()` carries `tenant_id=A` (never B) while A is active; empty when central — this payload is the SOLE channel that re-establishes per-job tenancy. (4) tenant B cannot `ImportRun::find()` tenant A's run (TenantScope), but it still exists globally (scope, not deletion, isolates).
- [x] 4.6 Frontend test `frontend/src/modules/import/__tests__/ConfirmStep.test.tsx` — **SCENARIO-06**: render `ConfirmStep` in StrictMode (double-invoke effects), mock `POST /execute`, assert it was called exactly once; assert exactly one `import_run` creation event.
- [x] 4.7 `Modules/DataImport/tests/Feature/InstrumentationTest.php` — **SCENARIO-08**: 5 tests. During silence: `DB::connection()->getQueryLog()` empty + `logging.query.except=['*']` + `slower_than=PHP_INT_MAX`. After restore: logger config restored EXACTLY; Telescope recording restored to pre-silence value; restore-without-silence is a safe no-op. End-to-end: a real import run leaves `logging.query.*` config untouched (job-scoped kill-switch, not global).
- [x] 4.8 `Modules/DataImport/tests/Feature/RunImportJobTest.php::test_scenario_10_recompute_runs_in_finalizer_not_per_row` — **SCENARIO-10**: anon-class spy over `ChargeStatusService` bound in container; asserts `recomputeUnitAggregates` called EXACTLY once (finalizer) with the set of unique affected units — never per row/chunk.
- [x] 4.9 `Modules/DataImport/tests/Feature/RunImportJobTest.php::test_scenario_11_guard_failure_counted_and_chunk_continues` (+ existing `ImportExecuteTest::test_blocked_row_is_counted_as_failed_not_executed` from slice 1) — **SCENARIO-11**: a guard-failing row (monto=null) in the MIDDLE of a chunk → counted `failed` with error in `report.rows`, rows BEFORE and AFTER both processed (chunk did not crash). Plus coordinator parse-failure path: `test_coordinator_parse_failure_marks_run_failed_not_stuck` (bad workbook → `status=failed`, `phase=done`, `error` set; never stuck processing).

## Phase 5: Cleanup / Wiring

- [x] 5.1 No explicit job registration required (auto-discovered). The `imports` queue is a logical named queue on the default `database` connection (no new connection config needed); ops runs `php artisan queue:work --queue=imports` with a SINGLE worker for v1 concurrency=1. Documented via `RunImportJob::QUEUE` const + docblock.
- [x] 5.2 `ImportRun::$fillable` includes `'phase'`; the ALTER migration `2026_06_14_120000_add_phase_to_import_run_table.php` runs cleanly (`php artisan migrate` applied + RefreshDatabase fresh-schema tests green; no conflicts).
- [x] 5.3 Confirmed: zero remaining callers of `ImportRunner::execute()`; the only `RunImportJob::dispatch` is the updated controller call passing `$run->id`.
- [x] 5.4 R1 confirmed at apply time: the installed package is `overtrue/laravel-query-logger` (config `logging.query.*`), NOT `antoniors/laravel-sql-logger`. `SilencesInstrumentation` uses `logging.query.except => ['*']` + `slower_than => PHP_INT_MAX` (the off-switch the boot-registered listener actually re-reads per query) instead of the assumed `sql-logger.*` keys.
