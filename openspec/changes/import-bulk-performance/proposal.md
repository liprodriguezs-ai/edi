# Proposal — import-bulk-performance

**Change:** `import-bulk-performance`
**Module:** `Modules/DataImport` (payment importer) + `frontend/src/modules/import`
**Status:** proposed
**Date:** 2026-06-14

---

## 1. Intent

### Problem
The payment importer fails on real-world files. A live test of a 1000-row import surfaced
a chain of defects, all verified against the running system:

- The job (`RunImportJob`, `ShouldQueue`) ran **inline** because dev had `QUEUE_CONNECTION=sync`.
  PHP killed the `POST /import/execute` request mid-loop → 500 with no CORS header → the browser
  reported a misleading "CORS error", and **partial rows were committed** with no honest outcome.
- With a worker on `memory_limit=128M`, the job **OOM'd at ~44 rows**. Root cause is not the
  algorithm: `laravel/telescope` records every query/model/event in memory (`telescope_entries`
  already holds ~274k rows), a custom `laravel-sql-logger` accumulates, and `APP_DEBUG=true`
  retains debug state — all across the per-row loop. At `memory_limit=1024M` the same 1000 rows
  completed. Memory is leaking from instrumentation, not from the import data itself.
- `ImportRunner::execute()` does **one `$run->save()` per row** (~1000 progress UPDATEs) and wraps
  **each row in its own `DB::transaction`** (~1000 transactions), and it **re-validates every row**
  at the top of `execute()` even though `/validate` already ran that exact dry-run.
- It is **one monolithic job** processing all rows sequentially — "se suponía que se haría por
  chunks; un solo job es lo mismo que un request" (user). No chunking, no memory relief between
  segments, no granular progress, no path to parallelism.
- The frontend double-fires `executeImport` under React StrictMode (the `cancelled` flag suppresses
  only the first *result*; **two `POST /execute` still fire**, creating two `import_run` rows + two
  jobs for one user action).
- **Partial failures are silently swallowed**: per-row exceptions are caught, appended to
  `report.rows`, but the run still ends `status=ready`. A run that created 912/1000 reported plain
  "success".
- Progress UX is dishonest: no phase labels ("leyendo archivo", "procesando X/N", "finalizando"),
  and `progress.total` starts at `0` so the bar sits at 0/0 until the loop begins.

### Why now
The importer is the onboarding on-ramp for every new cluster — an administrator's first action is
loading their historical payment data. A 1000-unit cluster is a normal size in Mexico, and the
importer must not fail, lie about success, or hang on a dead progress bar. The instrumentation
memory leak makes the failure non-deterministic (works at 1G, dies at 128M), which is exactly the
kind of bug that escapes staging and detonates in production.

### Success looks like
- A **≥1000-row** file imports reliably and completes **regardless of a sane worker `memory_limit`**
  (target: stable under 256M for 1000 rows), because instrumentation is disabled during execution.
- Work is processed **in chunks** with memory released between chunks and **monotonic, honest
  progress** the FE can render with a phase label.
- The FE fires **exactly one** `POST /execute` per user action (StrictMode-safe).
- A run with N failed rows ends in a **distinct, surfaced outcome** ("completado con errores")
  carrying the failed/succeeded/skipped counts — never a silent "success".
- The existing domain contracts are **preserved unchanged**: tenancy via `QueueTenancyBootstrapper`,
  idempotency (folio first, then `unit_id + paid_at + total_amount` natural key, CANCELLED excluded),
  FIFO + bank balance + credit + receipt-number via `PaymentService::register`, and the
  `ChargeStatusService` unit-aggregate recompute.

---

## 2. Scope

### In scope
1. **Instrumentation kill-switch during execution (the key fix).** At the start of import processing
   (job/chunk boot), disable the query log and Telescope recording so memory does not accumulate
   across the loop, independent of `memory_limit`:
   - `DB::connection()->disableQueryLog();`
   - `if (class_exists(\Laravel\Telescope\Telescope::class)) { \Laravel\Telescope\Telescope::stopRecording(); }`
   - Mitigate the custom `laravel-sql-logger` the same way (disable its listener / stop recording for
     the run). Investigate whether any per-iteration unset of resolved containers is also warranted.
   This is the single most important stability change and must land even if chunking is descoped.

2. **Chunked processing.** Replace the single `RunImportJob` that loops all rows with a
   **`Bus::batch` of `ProcessImportChunkJob`** (chunk size ~100–250, tuned to the memory budget),
   plus a **finalizer** that runs after the batch completes. Each chunk job:
   - re-reads only its slice of rows from the stored file (or a pre-split cache — see Design),
   - executes those rows through the existing `RowImporter::execute` path,
   - releases memory at job boundaries (fresh process/worker tick per job).

3. **Progress overhaul.**
   - Set `progress.total` and an explicit **phase** as early as possible: `reading_file` →
     `processing` (with `processed/total`) → `finalizing` → terminal.
   - Persist progress **per chunk / every N rows**, not per row (drop the ~1000 single-row UPDATEs).
   - Extend `import_run` to carry the phase and the failed-row count (additive ALTER migration; or a
     phase/counts key inside the existing `progress`/`report` JSON — decided in Design).

4. **Remove the redundant re-validation in `execute()`.** Do not re-run the full dry-run inside
   execution. Trust the contract that `/validate` already gated blocked rows, while keeping per-row
   defensive guards so a malformed row degrades to a reported per-row failure (not a crashed chunk).

5. **Honest partial-failure outcome.** Introduce a terminal state/flag such as
   `completed_with_errors` (or `ready` + `report.failed_count > 0` made first-class) so a run with
   failed rows is visibly distinct from a clean success. Surface succeeded / skipped / failed counts
   and the per-row error list in the FE report.

6. **Bank-balance contention strategy under chunking.** Because `PaymentService::register` already
   takes `lockForUpdate` on the destination `bank_account` (and on `credit_balance`, the unit's
   charges, and the per-tenant/year `receipt_sequence` counter row), concurrent chunks writing the
   same account / sequence contend. Decide and implement a contention-safe approach (see Approach §6).

7. **Frontend single-fire guard.** Add a started-ref guard in `ConfirmStep.tsx` so `executeImport`
   fires exactly once under StrictMode; keep the existing `cancelled` cleanup for the poller.

8. **Frontend progress UX.** Render the phase label ("Leyendo archivo…", "Procesando X / N
   filas…", "Finalizando…") and the partial-failure outcome banner + counts in the report step.
   Update `schema.ts` interfaces (`IImportProgress` phase, `IImportRun` status, `IImportReport`
   counts) to match the new backend contract.

### Out of scope
- **Phone unique constraint** — already removed this session via migration
  `2026_06_14_193500_drop_phone_unique_from_user_table` (applied). Noted only as a completed
  prerequisite; **do not re-propose**.
- Changing the **money model** (minor units + `cknow/laravel-money`), the **idempotency keys**, the
  **FIFO algorithm**, or the **ChargeStatusService** semantics. The importer keeps calling
  `PaymentService::register` unchanged; this change is about *how the loop is driven*, not what each
  row does.
- Streaming-XLSX rewrite of `WorkbookReader` (it currently uses `Excel::toArray`, which loads the
  whole sheet into memory). Flagged as a follow-up risk; **chunked execution + instrumentation
  kill-switch** are expected to clear the 1000-row target without it. Revisit only if a much larger
  file (e.g. 10k+ rows) becomes a requirement.
- The `/validate` dry-run endpoint behavior (it stays a synchronous zero-write preview).
- Real-time push progress (WebSocket/SSE). Polling stays; only its payload gets richer.
- Auth/permissions, route middleware, and the tenant-scoped storage path — unchanged.

---

## 3. Approach

### 3.1 Overall shape
Keep the public surface identical: `POST /import/execute` still stores the tenant-namespaced file,
creates the `import_run`, and returns `import_run_id` immediately; the FE still polls
`GET /import/{id}/status`. What changes is the engine behind it: a dispatcher creates a
`Bus::batch` of chunk jobs plus a finalizer, instead of one job that loops everything.

```
POST /import/execute
  └─ store file, create import_run(status=pending, progress={phase:reading_file, processed:0, total:0})
  └─ dispatch RunImportJob (orchestrator)

RunImportJob (orchestrator, queued)
  ├─ disableQueryLog + stopRecording (instrumentation kill-switch)
  ├─ phase=reading_file → parse workbook once, count rows, set progress.total
  ├─ build chunks (size ~100–250)
  └─ Bus::batch([ ProcessImportChunkJob × K ])
         ->then(FinalizeImportJob)   // success path
         ->catch(...)->finally(FinalizeImportJob)  // always finalize honestly

ProcessImportChunkJob (queued, one per chunk)
  ├─ disableQueryLog + stopRecording
  ├─ execute its rows via RowImporter::execute (existing idempotency + FIFO path)
  ├─ accumulate per-chunk counters + issues (persisted to import_run.report incrementally / atomically)
  └─ bump progress.processed by the chunk size

FinalizeImportJob
  ├─ recompute unit aggregates for all affected units (ChargeStatusService, batched)
  ├─ (if deferred-balance strategy) recompute bank_account.current_balance once from confirmed payments
  ├─ compute terminal status: ready | completed_with_errors | failed
  └─ phase=done, write final report (succeeded/skipped/failed counts, bank_balance_delta, rows)
```

### 3.2 Why a batch + finalizer (vs sequential chunked iteration in one job)
Two viable designs were weighed:

- **(A) Sequential chunked iteration inside one job** — loop chunks in a single `RunImportJob`,
  unsetting/freeing between chunks. Simplest; gives memory relief and chunked progress; **no
  cross-chunk lock contention** because nothing runs concurrently. But it keeps one long-lived
  worker process, so leaked memory from any library that ignores our kill-switch still accumulates
  within that one process, and there is no parallelism headroom.
- **(B) `Bus::batch` of chunk jobs + finalizer** — each chunk is a **separate job tick**, so the
  worker reclaims memory between chunks at the process boundary (the strongest memory guarantee),
  progress is naturally chunk-granular, the finalizer gives one honest place to compute the terminal
  outcome and the aggregate recompute, and Laravel's batch primitives give retry/`catch`/`finally`
  for free.

**Recommendation: (B), run with `concurrency = 1` initially (chunks execute one-at-a-time on the
batch).** This captures B's memory and structure wins while **sidestepping the contention problem
entirely for v1** — chunks do not overlap, so the existing `lockForUpdate` on bank account, credit,
charges, and the `receipt_sequence` counter behaves exactly as it does today. The architecture then
*allows* raising concurrency later without a rewrite, once the contention strategy below is proven.

### 3.3 The bank-balance / sequence contention reality (decisive detail)
`PaymentService::register` is heavier than "increment a bank balance". Inside its own
`DB::transaction` it `lockForUpdate`s:
- the destination `bank_account` (`current_balance` read-modify-write),
- the unit's `credit_balance` and the unit's open `charges` (FIFO),
- **the per-tenant/per-year `receipt_sequence` counter row** — to mint `R-YYYY-NNNNNN`.

That **`receipt_sequence` lock is shared by EVERY payment in the run** (same tenant, same year).
So even if two chunks touch different bank accounts and different units, **every payment still
serializes on the receipt-sequence row**. Parallel chunks would therefore buy little throughput on
the payment write path while adding real deadlock surface (lock-ordering across account + credit +
charges + sequence). This is the core reason the recommendation runs chunks **sequentially (concurrency=1)**
for v1: we get chunked memory/progress without paying for contention we can't actually parallelize
around.

If/when parallelism is pursued later, the design keeps two escape hatches, to be chosen in Design:
- **Deferred bank-balance finalizer:** chunks register payments but the destination
  `bank_account.current_balance` is **recomputed once** in `FinalizeImportJob` from the confirmed
  payments (single locked write per account), removing per-row account contention. (The
  receipt-sequence lock still serializes folio minting — acceptable, it's a short critical section.)
- **`lockForUpdate` + bounded retry on deadlock** at the chunk level, with a deterministic lock
  order. Heavier and more failure-prone; only if measured throughput demands it.

### 3.4 Instrumentation kill-switch (must-land)
Disabling the query log + Telescope + the custom SQL logger at job/chunk boot is the change that
makes the importer survive a normal `memory_limit`. It is independent of chunking and ships even if
everything else is descoped. Guard Telescope with `class_exists` so non-Telescope environments are
unaffected.

### 3.5 Idempotency & re-runnability preserved
Chunking is **safe to retry** precisely because each row is already idempotent: folio match → skip;
natural-key (unit+date+amount, CANCELLED excluded) → skip. A retried chunk re-skips rows it already
imported. The finalizer's terminal-status computation must read the accumulated per-chunk counters,
not assume the happy path.

### 3.6 Frontend
- **Single-fire:** a `startedRef` set true on first `startExecution()` invocation; the effect early-
  returns if already started. The existing `cancelled` flag remains for poll cleanup only.
- **Phase + counts UI:** map `progress.phase` to Spanish labels and show the partial-failure banner
  with succeeded/skipped/failed counts. Update `schema.ts` to the new contract (phase field on
  progress, `completed_with_errors` on status, `failed_count`/`succeeded_count` on report).
- Conventions: keep `ApiError` handling, no `any`, Spanish UI text, existing wizard structure.

---

## 4. Risks & open questions
- **WorkbookReader loads the whole sheet** (`Excel::toArray`) into memory before chunking. For 1000
  rows this is fine once instrumentation is silenced; for much larger files it becomes the next
  ceiling. Open question for Design: parse-once-and-cache-slices vs each chunk re-parsing the file
  (re-parsing is simplest but re-pays the read cost per chunk). **Recommendation:** parse once in the
  orchestrator, persist normalized rows to a cache/temp store keyed by run, chunk jobs read their
  slice — but only if memory allows; otherwise sequential single-job chunked iteration (Design A)
  avoids the question.
- **Terminal-state modeling:** new `completed_with_errors` status vs `ready` + first-class
  `report.failed_count`. Affects the FE contract and any consumer of `import_run.status`. Decide in
  Design; prefer the additive option that least disturbs existing readers.
- **Progress write contention:** many chunk jobs updating one `import_run.progress` row need a safe
  increment (locked read-modify-write or atomic counter) so concurrent (future) chunks don't clobber
  each other's `processed`. With concurrency=1 this is moot for v1 but the schema/approach should not
  preclude it.
- **`receipt_sequence` is the true serialization point** — confirmed by reading PaymentService.
  Any future parallelism claim must account for it; do not assume parallel chunks linearly speed up
  the payment write path.
- **Batch + tenancy:** `Bus::batch` jobs must each carry tenant context. `QueueTenancyBootstrapper`
  already re-initializes tenancy per job from the payload; verify the finalizer and chunk jobs all
  serialize the tenant correctly (they receive the `import_run`, which is tenant-scoped).
- **Telescope backlog:** `telescope_entries` (~274k rows) is a separate operational cleanup, not
  part of this change, but worth flagging to ops.

---

## 5. Out-of-scope completed prerequisite (for the record)
- `user_phone_unique` constraint removed via `2026_06_14_193500_drop_phone_unique_from_user_table`
  (applied this session). It previously blocked imports on phone collisions. **Done — not part of
  this change's work.**

---

## 6. Next phases
- `sdd-spec` — requirements + scenarios (memory ceiling, chunk size, progress phases, partial-
  failure outcome, idempotent retry, single-fire FE).
- `sdd-design` — chunk-job topology (Bus::batch vs sequential), terminal-state modeling + migration
  decision, row-slice caching vs re-parse, bank-balance finalizer vs lock-retry, progress increment
  safety, FE contract changes.
  (spec and design can run in parallel.)
