# Spec — import-bulk-performance

**Change:** `import-bulk-performance`
**Module:** `Modules/DataImport` + `frontend/src/modules/import`
**Status:** spec
**Date:** 2026-06-14
**Based on proposal:** `openspec/changes/import-bulk-performance/proposal.md`

---

## Overview

This spec describes what MUST be true after the import-bulk-performance change is applied. It covers the backend job engine, progress model, idempotency, partial-failure handling, tenancy, and frontend UI contract. It does NOT prescribe implementation; see the design artifact for architectural decisions.

---

## REQ-01 — Instrumentation kill-switch

**Requirement:** At the start of every import job (orchestrator, chunk, and finalizer), all instrumentation that accumulates per-query or per-event memory MUST be disabled for the duration of the job.

**Invariants:**
- `DB::connection()->disableQueryLog()` MUST be called before any row processing begins.
- If the `Laravel\Telescope\Telescope` class exists in the running environment, `Telescope::stopRecording()` MUST be called. The guard MUST use `class_exists(\Laravel\Telescope\Telescope::class)` so non-Telescope environments are unaffected.
- Any custom SQL logger (e.g. `laravel-sql-logger`) MUST be silenced by the same guard (disable its listener or stop its recording) for the job's lifetime.
- These calls MUST execute even if chunking is not applied (they are the minimum required fix).

**Measurable outcome:** A 1000-row import on a worker with `memory_limit=256M` MUST complete without OOM termination when instrumentation is disabled. (Workers at `memory_limit=128M` are a stretch goal, not a hard requirement for v1.)

---

## REQ-02 — Chunked processing via Bus::batch

**Requirement:** Row execution MUST be split into discrete chunk jobs dispatched as a Laravel `Bus::batch`, not processed in a single monolithic loop.

**Invariants:**
- Chunk size MUST be between 100 and 250 rows. The exact value is a design decision; the spec requires it fall in this range.
- `concurrency` for the batch MUST be set to `1` for v1. Chunks execute one-at-a-time on the queue. No two chunk jobs for the same import run may be in-flight simultaneously.
- The batch MUST include a finalizer step that runs after all chunks complete (success path) AND on any partial-failure path (i.e., `->catch` and `->finally` semantics so the run always reaches a terminal state with an honest report).
- Each chunk job MUST release memory at the process boundary. No state from a previous chunk job may persist in the worker process for the next chunk job.
- The orchestrator job MUST set `progress.total` before dispatching the batch (i.e., before any chunk runs).

---

## REQ-03 — Progress phases (phased, honest, chunk-granular)

**Requirement:** Import progress MUST be phased, set before rows are processed, and persisted per chunk — not per row.

### REQ-03a — Phase contract

The `import_run` MUST carry a `phase` field with the following finite set of values:

| Phase key | When set |
|---|---|
| `reading_file` | Orchestrator job starts; workbook is being parsed |
| `processing` | Batch of chunk jobs is running; `processed` advances per chunk |
| `finalizing` | Finalizer job is running; aggregate recomputes and terminal status determination |
| `done` | Run has reached a terminal status (`ready`, `completed_with_errors`, or `failed`) |

**Invariants:**
- The phase MUST transition monotonically forward. It MUST NOT go backward.
- `progress.total` MUST be set (to the actual row count) before phase transitions to `processing`.
- `progress.processed` MUST be `0` when phase is `reading_file`.
- `progress.processed` MUST equal `progress.total` when phase is `done` (even if some rows failed or were skipped — processed means attempted, not succeeded).

### REQ-03b — Granularity

- Progress MUST be persisted once per chunk completion, not once per row processed.
- A run with 1000 rows and chunk size 100 MUST produce at most 10 + 2 (orchestrator + finalizer) progress persists, not 1000.

### REQ-03c — FE polling contract

The status endpoint `GET /import/{id}/status` MUST return:

```json
{
  "id": "uuid",
  "status": "processing",
  "phase": "processing",
  "progress": {
    "total": 1000,
    "processed": 400
  },
  "report": null
}
```

When terminal:

```json
{
  "id": "uuid",
  "status": "completed_with_errors",
  "phase": "done",
  "progress": {
    "total": 1000,
    "processed": 1000
  },
  "report": {
    "succeeded_count": 912,
    "skipped_count": 50,
    "failed_count": 38,
    "rows": [
      { "row": 14, "error": "Unidad no encontrada: A-101" }
    ]
  }
}
```

---

## REQ-04 — Idempotency preserved

**Requirement:** Re-importing the same file (or retrying a failed run) MUST produce the same final state as the first successful import. Already-imported rows MUST be silently skipped.

**Invariants:**
- Idempotency check order: (1) folio match (`receipt_number`) → skip; (2) natural-key match (`unit_id + paid_at + total_amount`, CANCELLED excluded) → skip.
- A skipped row MUST increment `skipped_count` in the report, not `succeeded_count`.
- A retried chunk job MUST re-skip rows it already imported in a previous attempt of the same run.
- Idempotency MUST be enforced inside the existing `PaymentService::register` path. The importer MUST NOT duplicate the idempotency logic outside that path.

---

## REQ-05 — Partial-failure outcome

**Requirement:** A run that processed rows but encountered per-row errors MUST reach a terminal state that is visually and semantically distinct from a clean success.

**Invariants:**
- Terminal statuses MUST include at least three values: `ready` (clean success, 0 failed rows), `completed_with_errors` (some rows failed, at least 1 succeeded or skipped), `failed` (run could not complete — job exception, unrecoverable error, or 0 rows processed).
- A run with `failed_count > 0` MUST NOT have `status = 'ready'`.
- Per-row error messages MUST be stored on the run report (the `rows` array) with at minimum: row number and human-readable error string.
- The FE MUST display the `completed_with_errors` outcome with a distinct visual treatment (e.g. amber/warning banner) separate from `ready` (green/success) and `failed` (red/error).
- `succeeded_count + skipped_count + failed_count` MUST equal `progress.total` on the terminal report.

---

## REQ-06 — Tenancy preserved across batch jobs

**Requirement:** Each batch job (orchestrator, chunk, finalizer) MUST execute within the correct tenant context for the import run.

**Invariants:**
- `QueueTenancyBootstrapper` MUST re-initialize tenancy per job from the job payload. The importer MUST NOT require a custom tenancy bootstrap — the existing mechanism is sufficient.
- The `import_run` model carries `tenant_id` (it is a tenant-scoped entity); each chunk job receives the `import_run_id` and thus can recover tenant context when `BelongsToTenant` + `TenantScope` are active.
- No chunk job or finalizer job MUST read or write rows belonging to a different tenant, even under `concurrency = 1`.
- The finalizer MUST assert (or rely on the model scope to enforce) that it only reads charges and credit balances for the correct tenant.

---

## REQ-07 — Domain contracts unchanged

**Requirement:** The following domain contracts MUST remain unchanged after this change is applied.

**Invariants:**
- All payment amounts MUST be stored as `bigInteger` in minor units (centavos). No decimal or float column may be introduced for money.
- `PaymentService::register` is the sole entry point for creating payments. The importer MUST call it unchanged. No direct payment insertion bypassing this service is permitted.
- The FIFO payment application algorithm (order: `due_date` asc → `issued_at` asc → `id`) MUST remain unchanged.
- `bank_account.current_balance` MUST be updated as part of `PaymentService::register` (or in the finalizer if a deferred-balance strategy is chosen in design — but one of these two paths MUST execute, not neither).
- `ChargeStatusService` unit-aggregate recompute (worst-wins across a unit's charges) MUST execute for every affected unit after all rows of the import run have been processed. This MUST happen in the finalizer step, not per-row.
- The `receipt_number` (format `R-YYYY-NNNNNN`) generation via the per-tenant/per-year `receipt_sequence` counter row and `lockForUpdate` MUST remain the only mechanism for minting receipt numbers. The importer MUST NOT assign receipt numbers outside this path.
- Credit balance application MUST continue to happen inside `PaymentService::register`, not deferred.

---

## REQ-08 — Re-validation not repeated during execution

**Requirement:** The full row-validation dry-run executed by `POST /import/validate` MUST NOT be repeated at the top of execution-phase jobs.

**Invariants:**
- Execution jobs MUST NOT call the dry-run validator on each row before processing.
- A lightweight per-row defensive guard (e.g., check that the unit exists, that required fields are non-null) MAY run; if a row fails this guard, it is counted as a `failed` row with an error message, not a crash.
- The contract is: `/validate` gates blocked rows; the execution path trusts that contract and adds only minimal defensive guards.

---

## REQ-09 — Frontend: execute fires exactly once

**Requirement:** The `POST /import/execute` request MUST fire exactly once per user action, regardless of React StrictMode double-invocation of effects.

**Invariants:**
- A `startedRef` (or equivalent guard with stable identity across StrictMode double-invocations) MUST be set to `true` on the first call to the execute function.
- If the effect fires a second time (StrictMode behavior), the guard MUST cause the function to return immediately without making a second HTTP call.
- At most ONE `import_run` row MUST be created per user-initiated execute action.
- The existing `cancelled` cleanup flag for the status-poller MUST remain unmodified (it addresses effect cleanup, not double-fire).

---

## REQ-10 — Frontend: phased progress UI

**Requirement:** The import wizard MUST display the current phase in Spanish and honest progress.

**Invariants:**
- Phase keys from the backend MUST map to Spanish display strings:
  - `reading_file` → "Leyendo archivo…"
  - `processing` → "Procesando {processed} / {total} filas…"
  - `finalizing` → "Finalizando…"
  - `done` → (transition to result step)
- The progress bar MUST show `processed / total`. It MUST NOT show `0 / 0` when `total` is known; if `total = 0` and `phase = reading_file`, the bar MUST show an indeterminate/spinner state.
- The phase label MUST be visible to the user at all times while the run is non-terminal.

---

## REQ-11 — Frontend: partial-failure display

**Requirement:** A `completed_with_errors` run MUST be displayed with a distinct visual treatment and expose the full failure detail.

**Invariants:**
- `status = 'ready'` → green success banner with `succeeded_count` and `skipped_count`.
- `status = 'completed_with_errors'` → amber/warning banner with `succeeded_count`, `skipped_count`, and `failed_count` all visible.
- `status = 'failed'` → red error banner with the run-level error message.
- The per-row error list (`report.rows`) MUST be rendered when `failed_count > 0`, showing at minimum: row number and error message. It MUST be scrollable if the list is long.
- `schema.ts` interfaces (`IImportProgress`, `IImportRun`, `IImportReport`) MUST be updated to type the `phase` field, the `completed_with_errors` status variant, and the `succeeded_count`/`skipped_count`/`failed_count` fields. No `any` allowed.

---

## REQ-12 — No double-import under StrictMode (integration)

**Requirement (integration of REQ-09):** The database MUST NOT contain duplicate `import_run` rows for a single user-initiated execute action. This is an observable contract, not just a FE concern.

**Invariants:**
- After one user-initiated execute action, exactly one `import_run` row with the matching `tenant_id`, `user_created_id`, and `created_at` (within the same second) MUST exist.
- If two `POST /import/execute` requests arrive in rapid succession (within 2 seconds) for the same tenant and file, the backend MAY accept the second and create a second run — but the FE guard (REQ-09) MUST prevent this from happening under normal operation.

---

## Out-of-scope (confirmed, do NOT test or enforce)

- `QUEUE_CONNECTION` environment variable change (already done as prerequisite).
- Phone unique constraint removal (migration `2026_06_14_193500` already applied).
- `WorkbookReader` streaming XLSX rewrite.
- Real-time push progress (WebSocket/SSE); polling stays.
- Auth, permissions, route middleware, tenant-scoped storage path.
- The `/validate` dry-run endpoint behavior.
- Any parallelism (concurrency > 1) for chunk jobs.

---

## Acceptance Scenarios

### SCENARIO-01 — Happy path: 1000 valid rows under normal worker memory

**Given:** A worker running with `memory_limit=256M`, Telescope enabled in the environment, a valid XLSX file with 1000 unique payment rows (all units exist, all amounts positive, no duplicate folios).

**When:** `POST /import/execute` is called once by an authenticated tenant user.

**Then:**
- Exactly one `import_run` row is created (status `pending`, phase `reading_file`).
- The run transitions through phases: `reading_file` → `processing` → `finalizing` → `done`.
- The worker does NOT OOM; no PHP fatal errors appear in the log.
- The final `status` is `ready`.
- `report.succeeded_count` = 1000, `report.skipped_count` = 0, `report.failed_count` = 0.
- `progress.processed` = 1000, `progress.total` = 1000.
- Each of the 1000 rows has a `payment` record in the database with a valid `receipt_number` (format `R-YYYY-NNNNNN`).
- `ChargeStatusService` ran aggregate recompute for all affected units.

---

### SCENARIO-02 — Chunked processing: progress is chunk-granular

**Given:** A 500-row file; chunk size is 100.

**When:** The import runs.

**Then:**
- There are exactly 5 chunk jobs + 1 orchestrator job + 1 finalizer job = 7 jobs dispatched.
- `progress.processed` advances in increments of ~100 (one persist per chunk), not one per row.
- The number of UPDATEs to `import_run.progress` is ≤ 10 for the full run (5 chunks + orchestrator + finalizer).

---

### SCENARIO-03 — Partial failure: some rows error

**Given:** A 1000-row file where 38 rows reference unit codes that do not exist in the tenant's database.

**When:** The import executes.

**Then:**
- The run reaches `status = 'completed_with_errors'`, `phase = 'done'`.
- `report.succeeded_count` + `report.skipped_count` = 962.
- `report.failed_count` = 38.
- `report.rows` contains exactly 38 entries, each with the row number and an error message identifying the missing unit.
- The FE displays the amber/warning outcome banner with all three counts visible.
- `succeeded_count + skipped_count + failed_count` = 1000 = `progress.total`.

---

### SCENARIO-04 — Idempotency: re-import same file

**Given:** A 1000-row file that was previously imported successfully (all 1000 payments exist in the database).

**When:** The same file is re-imported.

**Then:**
- The run reaches `status = 'ready'`, `phase = 'done'`.
- `report.succeeded_count` = 0.
- `report.skipped_count` = 1000.
- `report.failed_count` = 0.
- No new `payment` rows are created.
- No new `payment_application` rows are created.
- `bank_account.current_balance` is unchanged from before this re-import.

---

### SCENARIO-05 — Tenancy isolation across chunk jobs

**Given:** Two tenants (A and B) each running an import simultaneously on separate workers.

**When:** Both imports execute their chunk jobs concurrently.

**Then:**
- Each chunk job for tenant A only reads and writes rows with `tenant_id = A`.
- Each chunk job for tenant B only reads and writes rows with `tenant_id = B`.
- No cross-tenant payment, charge, or credit-balance record is created.
- The `QueueTenancyBootstrapper` initializes tenancy correctly on each job from the job payload.

---

### SCENARIO-06 — No double-fire under React StrictMode

**Given:** The React application running in development StrictMode, where effects execute twice.

**When:** The user clicks "Ejecutar importación" once on the confirm step.

**Then:**
- The `startedRef` guard fires exactly once.
- Exactly one `POST /import/execute` HTTP request is sent.
- Exactly one `import_run` row is created in the database.

---

### SCENARIO-07 — Progress UI: phase labels displayed in Spanish

**Given:** The import wizard polling a run in progress.

**When:** The backend returns `phase = 'reading_file'`, then `phase = 'processing'` with `processed = 400, total = 1000`, then `phase = 'finalizing'`.

**Then:**
- The wizard displays "Leyendo archivo…" during `reading_file`.
- The wizard displays "Procesando 400 / 1000 filas…" during `processing`.
- The wizard displays "Finalizando…" during `finalizing`.
- The progress bar reflects `400 / 1000` while in `processing`.
- The progress bar shows an indeterminate state while `total = 0` (early `reading_file`).

---

### SCENARIO-08 — Instrumentation disabled in job context

**Given:** An environment where Telescope is enabled and `APP_DEBUG=true`.

**When:** An import chunk job boots.

**Then:**
- `DB::connection()->queryLog()` returns an empty array (query log was disabled).
- No new `telescope_entries` rows are created for queries executed within the chunk job.
- The custom SQL logger produces no entries for the chunk job's queries.
- After the job completes, other (non-import) requests continue to be recorded by Telescope normally (kill-switch is job-scoped, not global).

---

### SCENARIO-09 — Money in minor units, receipt numbers valid

**Given:** A row in the import file representing a payment of $1,500.00 MXN.

**When:** The row is processed.

**Then:**
- The `payment.total_amount` stored in the database is `150000` (integer minor units, not `1500.00` decimal).
- The `payment.receipt_number` matches the pattern `R-YYYY-NNNNNN` where YYYY is the current year and NNNNNN is a unique sequential number within the tenant for that year.
- No `decimal` or `float` column is used for the stored amount.

---

### SCENARIO-10 — ChargeStatusService runs in finalizer, not per row

**Given:** A 1000-row import completing successfully.

**When:** The finalizer job runs.

**Then:**
- `ChargeStatusService` unit-aggregate recompute is called once per unique affected unit (not 1000 times).
- All affected units have their `charge_status_id` updated to the correct worst-wins status after all payments have been applied.
- The recompute does NOT run during individual chunk jobs.

---

### SCENARIO-11 — Validation dry-run not repeated during execution

**Given:** A file that was previously validated via `POST /import/validate` (dry run passed).

**When:** The execution chunk job processes a row.

**Then:**
- The full dry-run validator is NOT called on the row.
- If the row fails a lightweight defensive guard (e.g., unit not found), it is counted as a failed row with an error in `report.rows`.
- No row failure causes a PHP exception that crashes the entire chunk job; other rows in the chunk continue processing.

---

## Type Contract (Frontend)

The following TypeScript interfaces MUST be present in `schema.ts` (or an equivalent type file for the import module) after this change:

```typescript
// Phase of the import run
type ImportPhase = 'reading_file' | 'processing' | 'finalizing' | 'done'

// Terminal status of the import run
type ImportStatus = 'pending' | 'processing' | 'ready' | 'completed_with_errors' | 'failed'

interface IImportProgress {
  total: number       // row count; 0 until reading_file completes
  processed: number   // rows attempted (succeeded + skipped + failed)
  phase: ImportPhase
}

interface IImportRowError {
  row: number
  error: string
}

interface IImportReport {
  succeeded_count: number
  skipped_count: number
  failed_count: number
  rows: IImportRowError[]  // non-empty only when failed_count > 0
}

interface IImportRun extends IEntity {
  status: ImportStatus
  phase: ImportPhase
  progress: IImportProgress
  report: IImportReport | null  // null until terminal state
}
```

No `any` allowed in these interfaces or in any component consuming them.
