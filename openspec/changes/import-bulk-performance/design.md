# Design — import-bulk-performance

**Change:** `import-bulk-performance`
**Module:** `Modules/DataImport` (payment importer) + `frontend/src/modules/import`
**Status:** designed
**Date:** 2026-06-14
**Reads:** `sdd/import-bulk-performance/proposal` (obs #1139)

---

## 0. Architecture summary (the HOW)

`POST /import/execute` keeps its public contract: store the tenant-namespaced file, create an
`ImportRun(status=pending)`, return `import_run_id` immediately. The engine behind it changes from
**one monolithic `RunImportJob` that loops every row** to a **coordinator job + a `Bus::batch` of
`ProcessImportChunkJob` (concurrency=1) + a `FinalizeImportJob`**, with an **instrumentation
kill-switch** at the boot of every import job.

```
POST /import/execute
  └─ store file → ImportRun(status=pending, progress={phase:reading_file, processed:0, total:0})
  └─ dispatch RunImportJob(importRun, absoluteFilePath)

RunImportJob (COORDINATOR — one queued job, NOT in the batch)
  ├─ silenceInstrumentation()                  ← kill-switch (see §1)
  ├─ status=processing, phase=reading_file
  ├─ parse workbook ONCE → normalized rows (WorkbookReader::parse)
  ├─ persist normalized rows to a run-scoped cache (see §2.2)
  ├─ set progress.total = data-row count, processed=0, phase=processing   ← total set EARLY
  ├─ build chunk descriptors (offset+length over the cached row list, CHUNK_SIZE=200)
  └─ Bus::batch([ ProcessImportChunkJob(importRunId, chunkIndex) × K ])
         ->name("import:{importRunId}")
         ->allowFailures()                      ← one bad chunk doesn't kill the batch
         ->onQueue('imports')
         ->finally(fn ($batch) => FinalizeImportJob::dispatch($importRunId))
         ->dispatch();
     // concurrency is NOT set on the batch → the default worker count governs it;
     // v1 runs a single worker on the 'imports' queue ⇒ effective concurrency = 1 (see §2.3)

ProcessImportChunkJob (queued, one per chunk)
  ├─ silenceInstrumentation()                  ← kill-switch, per job tick
  ├─ load its row slice from the run cache (offset+length)
  ├─ for each row: RowImporter::execute() inside a per-row DB::transaction (UNCHANGED domain path)
  ├─ accumulate per-chunk counters + issues
  ├─ persist counters/issues additively to import_run.report (locked RMW, see §2.4)
  └─ bump import_run.progress.processed by rows handled (locked RMW)

FinalizeImportJob (queued, runs once after the batch settles via ->finally)
  ├─ silenceInstrumentation()
  ├─ phase=finalizing
  ├─ ChargeStatusService::recomputeUnitAggregates(affectedUnitIds) ONCE, in a tx (see §2.5)
  ├─ compute terminal status from accumulated counters: ready | completed_with_errors | failed
  ├─ build bank_balance_delta from accumulated per-account deltas
  ├─ phase=done, write final report
  └─ delete the run cache + the stored file (cleanup)
```

This is **Design B** from the proposal: separate job ticks reclaim memory at the process boundary
(strongest memory guarantee), progress is chunk-granular, and the finalizer is the single honest
place for the terminal outcome + the aggregate recompute. Concurrency stays **1 for v1** because the
`receipt_sequence` lock serializes every payment regardless — see §3.

---

## 1. Instrumentation kill-switch (the must-land stability fix)

### 1.1 Where

A single private static helper, callable from all three jobs, lives in a tiny trait so the three
job classes share exactly one implementation and there is no copy-paste drift:

`Modules/DataImport/app/Jobs/Concerns/SilencesInstrumentation.php`

```php
namespace Modules\DataImport\Jobs\Concerns;

use Illuminate\Support\Facades\DB;

trait SilencesInstrumentation
{
    /**
     * Disable per-query memory accumulation for the duration of THIS job tick.
     *
     * Root cause of the OOM-at-44-rows: Telescope + the custom SQL logger + the
     * query log retain an entry per query across the whole loop. We disable all
     * three at job boot. This is scoped to the current worker process tick — see
     * the leak note in §1.3.
     */
    protected function silenceInstrumentation(): void
    {
        // 1. Eloquent/DB query log (unbounded array in memory while enabled).
        DB::connection()->disableQueryLog();

        // 2. Telescope — guarded so non-Telescope environments are unaffected.
        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::stopRecording();
        }

        // 3. Custom laravel-sql-logger — disabled via config at runtime so its
        //    query listener stops appending. The package reads config('sql-logger.*')
        //    per query; flipping the enabled flags in the container config silences it
        //    for the rest of this process tick without touching .env.
        config([
            'sql-logger.enabled' => false,
            'sql-logger.enabled_query_logging' => false,
            'sql-logger.enabled_slow_query_logging' => false,
        ]);
    }
}
```

> Implementation note for `sdd-apply`: confirm the exact config key namespace of the installed
> `laravel-sql-logger` package (`config/sql-logger.php`) and disable whatever listener flags it
> exposes. If the package binds a query listener at boot that ignores config, the fallback is
> `DB::connection()->flushQueryLog()` is NOT enough — instead resolve and detach is overkill;
> setting its config flags is the documented runtime off-switch. Treat the exact keys as a
> verify-time detail; the principle (turn it off at job boot) is fixed.

Call it as the FIRST statement of `handle()` in `RunImportJob`, `ProcessImportChunkJob`, and
`FinalizeImportJob`.

### 1.2 Why all three jobs and not just the chunk job

Telescope/SQL-logger accumulate on EVERY query, including the coordinator's parse-and-count pass and
the finalizer's aggregate recompute (which touches every affected unit's charges). Silencing only the
chunk jobs would leave two memory-heavy passes instrumented. The helper is cheap and idempotent, so
calling it per job is correct.

### 1.3 Does it leak to other jobs in the same worker? (explicit answer)

This is the decisive correctness question with a long-lived Octane/queue worker.

- `DB::connection()->disableQueryLog()` — the query log is **off by default** in production
  (`APP_DEBUG=false`). Leaving it off for subsequent jobs is harmless (other jobs do not depend on
  it). In dev (`APP_DEBUG=true`) it stays off until the next request/worker restart — also harmless.
  **Verdict: no harmful leak.**
- `Telescope::stopRecording()` — this DOES persist for the rest of the worker process. To avoid
  silencing Telescope for unrelated jobs that run later on the same worker, **re-enable it in a
  `finally` block** at the end of each job's `handle()` only if it was recording before:

  ```php
  $wasRecording = class_exists(\Laravel\Telescope\Telescope::class)
      && \Laravel\Telescope\Telescope::isRecording();
  $this->silenceInstrumentation();
  try {
      // ... job body ...
  } finally {
      if ($wasRecording) {
          \Laravel\Telescope\Telescope::startRecording();
      }
  }
  ```

  Decision: **restore Telescope in `finally`** so the kill-switch is strictly scoped to the import
  job tick and never blinds Telescope for the rest of the worker's lifetime. The query log and
  sql-logger config do not need restoration (off is the safe state and production default), but
  restoring them is also acceptable and symmetric — `sdd-apply` may restore all three for cleanliness.
- The custom SQL-logger config flip lives in the container config for the process; restore in the
  same `finally` if symmetry is wanted. Not restoring is harmless in production.

**Net rule:** silence at boot, restore Telescope in `finally`. The memory win comes from NOT
recording during the import; restoration keeps the worker honest for later jobs.

---

## 2. Chunked architecture (concrete decisions)

### 2.1 Job topology and classes

| Job | New/changed | Role |
|---|---|---|
| `RunImportJob` | **rewritten** | Coordinator: parse once, cache rows, set total, dispatch the batch + finalizer. No row writes. |
| `ProcessImportChunkJob` | **new** | One per chunk: execute its slice via `RowImporter::execute`, accumulate, persist progress + partial report. |
| `FinalizeImportJob` | **new** | Terminal state + aggregate recompute + report + cleanup. |
| `ImportRunner::execute()` | **removed/retired** | Its loop is replaced; `validate()` stays untouched for the dry-run. |
| `Concerns\SilencesInstrumentation` | **new** | Shared kill-switch. |

`ImportRunner::validate()` and `RowImporter::validate()` are UNCHANGED — `/validate` stays a
synchronous zero-write preview. `RowImporter::execute()` is UNCHANGED — the per-row idempotency +
PaymentService FIFO + money contract is preserved exactly (proposal scope-out).

### 2.2 Re-parse per chunk vs cache slices — DECISION: parse once, cache normalized rows

`WorkbookReader::parse()` uses `Excel::toArray`, which loads the WHOLE sheet into memory. Re-parsing
per chunk would re-pay that whole-sheet cost K times — for 5 chunks of a 1000-row file that is 5×
full-workbook loads, defeating the memory goal. So:

- The **coordinator parses once** and persists the normalized row Collection (already mapped to
  internal keys, empty rows dropped, keyed by 1-based spreadsheet row number) to a **run-scoped
  cache entry**.
- Storage choice: **a single JSON file on the same `Storage` disk** next to the upload, e.g.
  `imports/{tenantId}/{importRunId}.rows.json` — NOT the Cache facade.
  - Rationale: the normalized rows for 1000 rows are small (a few hundred KB of plain associative
    arrays), but they can grow; a file on disk avoids bloating Redis and is trivially cleaned up.
    The cache is per-tenant prefixed by `CacheTenancyBootstrapper` and would work, but a temp file
    is simpler to size-reason about and to delete deterministically in the finalizer.
  - Each `ProcessImportChunkJob` receives `(importRunId, offset, length)` and reads ONLY its slice:
    load the JSON, `array_slice` by offset/length, decode just that window. (For v1 simplicity it may
    json-decode the whole file and slice in memory — acceptable at 1000 rows with instrumentation
    off; flagged for the 10k+ follow-up where a line-delimited NDJSON slice read becomes worth it.)
  - The **row-number key is preserved inside each row record** (store as a list of
    `{ "_row": int, ...fields }`) so per-row issues keep the correct spreadsheet row number.

- **Escape hatch if memory still bites:** if profiling shows the whole-file load is the ceiling even
  with instrumentation off, fall back to Design A (sequential chunk iteration inside one coordinator
  job, unsetting between chunks). The cache-slice design is the primary; Design A is the documented
  fallback and needs no schema change.

> `WorkbookReader` streaming rewrite stays OUT of scope (proposal). Parse-once-cache clears the
> 1000-row target.

### 2.3 Chunk size and concurrency

- **`CHUNK_SIZE = 200`** rows. Rationale: at ~200 rows per job tick the per-tick working set
  (resolver memoization + the slice) stays small, the worker reclaims it between ticks, and a
  1000-row file is exactly 5 chunks (low batch overhead). 100 would also work; 200 halves batch
  bookkeeping for the same memory profile. Expose it as a class const so it is tunable without a
  contract change.
- **Concurrency = 1 for v1.** We do NOT set a Laravel batch "concurrency" option (Laravel batches
  don't throttle concurrency natively); instead we **run a single queue worker on a dedicated
  `imports` queue**. With one worker, chunk jobs execute one-at-a-time, so:
  - the `receipt_sequence` lock (shared by every payment, §3) behaves exactly as today,
  - no cross-chunk deadlock surface,
  - progress writes never race (but we still make them safe — §2.4 — so raising concurrency later is
    a worker-count change, not a rewrite).
- Jobs set `public int $tries = 1;` (idempotency makes a manual retry safe, but auto-retry of a
  half-applied chunk is avoided in v1; revisit with `tries > 1` once retry semantics are tested).
  `public int $timeout = 300;` per chunk (5 chunks × 5 min headroom ≫ real runtime once
  instrumentation is off).

### 2.4 Progress + partial-report writes — safe locked read-modify-write

Both `progress.processed` and the additive report counters are mutated by chunk jobs. Even though
v1 is concurrency=1, the write path is built concurrency-safe so raising workers later is free:

```php
// Inside ProcessImportChunkJob, after the slice is processed:
DB::transaction(function () use ($run, $chunkCounters, $chunkIssues, $rowsHandled): void {
    $locked = ImportRun::where('id', $run->id)->lockForUpdate()->firstOrFail();

    // progress: monotonic increment
    $progress = $locked->progress ?? [];
    $progress['processed'] = ($progress['processed'] ?? 0) + $rowsHandled;
    $progress['phase'] = ImportRun::PHASE_PROCESSING;
    $locked->progress = $progress;

    // report: additive merge of counters + append issues + accumulate deltas
    $report = $locked->report ?? ImportRun::emptyReport();
    $report['units_created']       += $chunkCounters['units_created'];
    $report['residents_created']   += $chunkCounters['residents_created'];
    $report['payments_registered'] += $chunkCounters['payments_registered'];
    $report['payments_skipped']    += $chunkCounters['payments_skipped'];
    $report['failed_count']        += $chunkCounters['failed_count'];
    $report['rows'] = array_merge($report['rows'], $chunkIssues);
    // bank deltas accumulated as {bank_account_id => minor_units}
    foreach ($chunkCounters['bank_deltas'] as $accId => $delta) {
        $report['bank_deltas'][$accId] = ($report['bank_deltas'][$accId] ?? 0) + $delta;
    }
    // affected unit ids accumulated as a set for the finalizer
    $report['affected_unit_ids'] = array_values(array_unique(
        array_merge($report['affected_unit_ids'] ?? [], $chunkCounters['affected_unit_ids'])
    ));
    $locked->report = $report;

    $locked->save();
});
```

- `lockForUpdate()` on the `import_run` row serializes the read-modify-write so two future
  concurrent chunks can't clobber each other's `processed` or counters.
- One progress/report write **per chunk** (5 writes for 1000 rows) instead of the old ~1000 per-row
  `$run->save()` — this directly kills the "~1000 progress UPDATEs" defect.
- `bank_deltas` and `affected_unit_ids` are interim accumulators stored inside `report`; the
  finalizer consumes them and replaces `bank_deltas` with the resolved `bank_balance_delta` array
  (account names) and DROPS the interim keys from the final stored report (keep the report contract
  clean for the FE).

### 2.5 Finalizer — terminal state + single aggregate recompute

`FinalizeImportJob` runs once via `->finally()` on the batch (so it runs whether the batch fully
succeeded, partially failed with `allowFailures()`, or was cancelled):

1. `silenceInstrumentation()`, set `phase=finalizing`.
2. Read the accumulated `report.affected_unit_ids`. Recompute unit aggregates ONCE:
   `DB::transaction(fn () => app(ChargeStatusService::class)->recomputeUnitAggregates($ids))`.
   This is the existing batch method (no per-unit N+1) — the same call the old `execute()` made, now
   in exactly one place. Wrapped in try/catch and logged as non-fatal (the import already wrote the
   payments; the aggregate is a safety net) — preserving current behavior.
3. Compute terminal status from the accumulated counters:
   - `failed` — if NOTHING was applied AND there were hard failures (e.g. the batch never ran / the
     coordinator failed before dispatching, or every chunk threw). Also the path the coordinator's
     own catch sets when parsing blows up before the batch.
   - `completed_with_errors` — if `report.failed_count > 0` (≥1 row threw an unexpected error during
     execute) — see §4.
   - `ready` — `failed_count === 0` (clean run; skipped rows due to idempotency are NOT errors).
3a. Resolve `bank_balance_delta` from `report.bank_deltas` (account name lookup, tenant-scoped —
    the existing `buildBankBalanceDelta` logic moves here), then strip the interim keys.
4. Set `phase=done`, persist the final `report` and `status`, `save()` under a lock.
5. Cleanup: delete `imports/{tenantId}/{importRunId}.rows.json` and the original upload file
   (best-effort, logged on failure).

> Why `->finally` and not `->then` + `->catch`: with `allowFailures()` a partially-failed batch
> still needs to finalize honestly. `->finally` fires in all settle paths, giving exactly one honest
> terminal computation. `->then`/`->catch` would split the terminal logic and risk a partial batch
> never finalizing.

### 2.6 job_batches table

`Bus::batch` requires the `job_batches` table — it already exists
(`backend/database/migrations/0001_01_01_000002_create_jobs_table.php`). No new migration for batch
plumbing. `job_batches` is a CENTRAL operational table (no `tenant_id`) — correct; batch metadata is
not tenant data, and the tenant context is carried INSIDE each job payload (§6), not in the batch row.

---

## 3. Bank-balance / receipt_sequence contention (why concurrency=1)

Confirmed by reading `PaymentService::register` → `applyConfirmedPayment`: inside ONE
`DB::transaction` it `lockForUpdate`s the destination `bank_account` (`incrementBankBalance`), the
unit's `credit_balance` (`applyFifo`), the unit's open `charges` (`openChargesFor`), and — via
`nextReceiptSequence` — the per-tenant/per-year `receipt_sequence` counter row to mint
`R-YYYY-NNNNNN`.

**The `receipt_sequence` row is shared by EVERY payment in the run** (same tenant + same year). So
even chunks touching different bank accounts and different units still serialize on that one counter.
Parallel chunks therefore buy almost nothing on the payment write path while adding real deadlock
surface (lock ordering across account + credit + charges + sequence). **Decision: v1 runs chunks
sequentially (one worker on `imports`).** We get chunked memory reclaim + chunk-granular progress
without paying for contention we cannot parallelize around.

**Future parallelism escape hatches (NOT built in v1, kept open by the design):**
- **Deferred bank-balance finalizer:** chunks register payments but the destination
  `bank_account.current_balance` is recomputed once in `FinalizeImportJob` from confirmed payments
  (single locked write per account), removing per-row account contention. Note this would require
  `PaymentService::register` to support a "skip bank increment" mode — OUT of scope for v1 because
  it changes PaymentService, which the proposal forbids touching.
- **`lockForUpdate` + bounded deadlock retry** with a deterministic lock order at the chunk level.
  Heavier; only if measured throughput demands it.

The `bank_deltas` accumulation in `report` (§2.4) already gives the finalizer the data it would need
for the deferred-balance strategy later, at zero cost now.

---

## 4. Partial-failure terminal state — DECISION

### 4.1 Recommendation: NEW terminal status `completed_with_errors` + first-class counts

Two options were weighed:
- **(A)** Keep `status=ready` and make `report.failed_count` first-class.
- **(B)** Add a new terminal status `completed_with_errors`.

**Chosen: (B) add `completed_with_errors`, AND add first-class counts (`failed_count`,
`succeeded_count`).** Reasoning:

- The proposal's success criterion is explicit: "A run with N failed rows ends in a **distinct,
  surfaced outcome** — never a silent success." A distinct STATUS is the least ambiguous way to make
  it distinct for every consumer of `import_run.status` (the FE banner, any future report listing,
  ops). Keeping `ready` and forcing every reader to also inspect `report.failed_count` is exactly the
  silent-swallow trap that caused the bug.
- The cost is small and additive: it is a new string constant + one FE union member. There is only
  ONE current consumer of `import_run.status` (the FE `ConfirmStep` poller + `IImportRun.status`),
  so the blast radius is tiny and fully under our control in this same change.
- We STILL add the counts so the banner can say "912 registrados, 88 con error" — status answers
  "distinct?", counts answer "how bad?".

`failed_count` here means **rows that threw an unexpected error during `execute()`** (the
`catch (Throwable)` path). Idempotency SKIPS (folio/natural-key duplicates) are NOT failures — they
are `payments_skipped` and never trigger `completed_with_errors`.

### 4.2 Migration (additive ALTER, never edit the create migration)

Per laravel-backend-architecture rule 12, add a new timestamped ALTER migration. We add `phase` as its own column
(cheap to read/index for ops, and avoids JSON-key churn on the hot progress path) and keep counts
inside the JSON `report` (they are report data, not query keys):

`Modules/DataImport/database/migrations/2026_06_14_..._add_phase_to_import_run_table.php`

```php
public function up(): void
{
    Schema::table('import_run', function (Blueprint $table): void {
        $table->string('phase')->nullable()->after('status')
            ->comment('Fase de la corrida: reading_file|processing|finalizing|done');
    });
}
public function down(): void
{
    Schema::table('import_run', function (Blueprint $table): void {
        $table->dropColumn('phase');
    });
}
```

- `failed_count` / `succeeded_count` live in the `report` JSON (no migration needed — `report` is
  already `json`). The progress phase ALSO mirrors into `progress.phase` for the FE single read of
  `progress` (the FE polls `progress`), while the `phase` column is the canonical server-side value.
  (Decision: write phase in BOTH `progress.phase` and the `phase` column; the FE reads
  `progress.phase`, ops reads the column.)
- The status `completed_with_errors` is a string in the existing `status` column — no migration,
  just a new model constant.

### 4.3 ImportRun model additions

```php
public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
public const STATUSES = [ ...pending, processing, ready, completed_with_errors, failed ];

public const PHASE_READING_FILE = 'reading_file';
public const PHASE_PROCESSING   = 'processing';
public const PHASE_FINALIZING   = 'finalizing';
public const PHASE_DONE         = 'done';

protected $fillable = [ 'status', 'phase', 'file_path', 'progress', 'report', 'error' ];

/** Canonical empty report shape so additive merges never hit undefined keys. */
public static function emptyReport(): array
{
    return [
        'total' => 0,
        'units_created' => 0,
        'residents_created' => 0,
        'payments_registered' => 0,
        'payments_skipped' => 0,
        'failed_count' => 0,
        'succeeded_count' => 0,
        'bank_balance_delta' => [],
        'rows' => [],
        // interim accumulators, stripped by the finalizer:
        'bank_deltas' => [],
        'affected_unit_ids' => [],
    ];
}
```

`succeeded_count` = `payments_registered` (kept explicit for the FE banner copy).

### 4.4 status() endpoint contract

`GET /import/{id}/status` returns `phase` too (additive, non-breaking):

```php
return response()->json([
    'status'   => $run->status,
    'phase'    => $run->phase,          // additive
    'progress' => $run->progress,       // now carries {phase, processed, total}
    'report'   => $run->report,
    'error'    => $run->error,          // already returned? add if missing (FE reads run.error)
]);
```

> Note: the current `status()` does NOT return `error`, but `ConfirmStep` reads `run.error`. Add
> `error` to the payload (additive) so the FE failure banner shows the real message.

---

## 5. Frontend design

### 5.1 Single-fire guard (StrictMode-safe) — `ConfirmStep.tsx`

The bug: React 18/19 StrictMode mounts effects twice in dev; the existing `cancelled` flag only
suppresses the first *result*, but BOTH `POST /execute` calls still fire → two `import_run` rows +
two jobs. Fix with a **module-instance `startedRef`** that gates the side effect itself, not just its
result:

```tsx
const startedRef = useRef(false)

useEffect(() => {
  if (startedRef.current) return   // StrictMode second invocation early-returns BEFORE the POST
  startedRef.current = true

  let cancelled = false
  async function startExecution() { /* ...existing body... */ }
  void startExecution()

  return () => {
    cancelled = true
    clearPoll()
    // do NOT reset startedRef — a remount must not re-fire execute for the same file/session
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [])
```

- `startedRef` guards the `POST /execute` so it fires **exactly once** per mount lifecycle even under
  StrictMode's double-invoke.
- The existing `cancelled` flag stays — it cleans up the poller and ignores late results after
  unmount. The two mechanisms are complementary: `startedRef` = "don't start twice",
  `cancelled` = "ignore results after teardown".

### 5.2 Phase label rendering

Map `progress.phase` → Spanish labels (no nested ternaries — use a lookup record per FE rules):

```tsx
const PHASE_LABELS: Record<ImportPhase, string> = {
  reading_file: 'Leyendo archivo…',
  processing:   'Procesando filas…',   // augmented with "X / N" below
  finalizing:   'Finalizando…',
  done:         'Completado',
}
```

- While `phase === 'processing'`, show `Procesando ${processed} / ${total} filas…`.
- `progress.total` is set EARLY by the coordinator (right after the parse-and-count, before any chunk
  runs), so the bar never sits at 0/0: during `reading_file` show an indeterminate "Leyendo
  archivo…" state (spinner, no count); once `processing` begins, `total > 0` and the determinate bar
  renders. The current `progressPercent` fallback chain stays but the `reading_file` phase renders
  the indeterminate label so the user never sees "0 / 0".

### 5.3 Distinct partial-failure vs success UI

`ConfirmStep`'s `ExecutionState` gains a `'done_with_errors'` state. The poller maps terminal status:

```tsx
if (run.status === 'ready') { clearPoll(); setState('done'); run.report && onComplete(run.report) }
else if (run.status === 'completed_with_errors') {
  clearPoll(); setState('done_with_errors'); run.report && onComplete(run.report)
}
else if (run.status === 'failed') { clearPoll(); setState('failed'); setErrorMessage(...) }
```

- `done` → green success banner (existing).
- `done_with_errors` → **amber/warning banner** (distinct color, e.g. `border-amber-200 bg-amber-50
  text-amber-800` + dark variants) reading e.g. *"Importación completada con errores: N filas no se
  pudieron procesar."* and still calls `onComplete(report)` so the report step renders the per-row
  error list + counts (succeeded / skipped / failed).
- `failed` → red error banner (existing) — reserved for a run that produced no usable outcome.

The report/summary step (wherever `onComplete` lands) shows the counts from
`report.payments_registered` (registrados), `report.payments_skipped` (omitidos por idempotencia),
and `report.failed_count` (con error), plus the `report.rows` issue list (already rendered today).

### 5.4 schema.ts additions

```ts
export type ImportRunStatus =
  | 'pending' | 'processing' | 'ready' | 'completed_with_errors' | 'failed'   // + completed_with_errors

export type ImportPhase = 'reading_file' | 'processing' | 'finalizing' | 'done'   // NEW

export interface IImportProgress {
  processed: number
  total: number
  phase?: ImportPhase          // NEW (optional for back-compat during rollout)
}

export interface IImportReport {
  total: number
  units_created: number
  residents_created: number
  payments_registered: number
  payments_skipped: number
  failed_count: number         // NEW
  succeeded_count: number      // NEW (== payments_registered, explicit for banner copy)
  bank_balance_delta: IBankBalanceDelta[]
  rows: IIssueRow[]
}

export interface IImportRun {
  // ...existing...
  status: ImportRunStatus
  phase?: ImportPhase          // NEW (top-level mirror of progress.phase)
  progress: IImportProgress | null
  report: IImportReport | null
  error: string | null
}
```

All additive; no `any`; Spanish UI text; existing wizard structure untouched. Conventions honored:
`ApiError` handling stays, no barrel files, responsive banners reuse the existing banner markup.

---

## 6. Tenancy — QueueTenancyBootstrapper carries tenant into every import job (confirmed)

Per stancl-tenancy §10 and the bootstrapper config: `QueueTenancyBootstrapper` serializes the current
`tenant_id` into the job payload and re-initializes tenancy when the job runs. This works for
chained/batched jobs the same as single jobs because EACH job carries its own payload.

- `RunImportJob`, `ProcessImportChunkJob`, `FinalizeImportJob` are all dispatched while tenancy is
  initialized (the coordinator runs in tenant context; it dispatches the batch + finalizer from
  within that context). The bootstrapper stamps tenant on each.
- **Pass the `importRunId` (string) into the chunk + finalize jobs, not the hydrated model**, and
  re-fetch via `ImportRun::findOrFail($id)` inside `handle()`. Reasons: (a) `SerializesModels` would
  otherwise re-query the model anyway; (b) passing the id keeps payloads tiny across K chunk jobs;
  (c) `ImportRun` uses `BelongsToTenant`, so `findOrFail` inside the re-initialized tenant scope is
  auto-scoped to the correct tenant — a cross-tenant id simply 404s, which is the safe failure.
- `buildBankBalanceDelta` / any direct `BankAccount` query in the finalizer keeps its explicit
  `->where('tenant_id', tenant('id'))` (matching existing code) since those are manual queries on a
  tenant table.
- The run-cache file path is already tenant-namespaced (`imports/{tenantId}/...`).

**Verification task for `sdd-apply`/`sdd-verify`:** assert that a chunk job, when run by the worker,
sees `tenant()` equal to the run's tenant (a feature test that dispatches the batch for tenant A and
asserts no row leaks to tenant B). This is the single highest-risk tenancy check.

---

## 7. Idempotency / FIFO / money — preserved unchanged

- `RowImporter::execute()` is called per row inside its own `DB::transaction` exactly as today —
  folio (`physical_receipt_number`) idempotency first, then natural key
  (`unit_id + paid_at + total_amount`, CANCELLED excluded). A retried chunk re-skips already-imported
  rows. **No change.**
- `PaymentService::register` (FIFO, credit consume-first, bank increment, `receipt_sequence` folio
  minting, minor units) is called unchanged. **No change.**
- Money stays bigInteger minor units via `cknow/laravel-money` casts. **No change.**
- `ReferenceResolver` memoization narrows from run-wide to **per-chunk** (each chunk job is a fresh
  process with its own resolver). This is acceptable: the auto-create paths
  (`resolveOrCreateUnit`/`resolveOrCreateBankAccount`) are already QUERY-FIRST and idempotent, so a
  type/account created in chunk 1 is FOUND (not re-created) by chunk 2's first DB lookup. The only
  cost is a few extra cache-miss DB reads per chunk for repeated names — negligible and bounded by
  chunk size. **Behavior-preserving.**

---

## 8. ADR-style decision log

| # | Decision | Rationale | Rejected alternative |
|---|---|---|---|
| D1 | `Bus::batch` of chunk jobs + finalizer (Design B) | Memory reclaimed at job-tick boundary (strongest guarantee); chunk-granular progress; one honest finalizer; free retry/finally primitives | Design A (sequential chunks in one job) — kept only as memory fallback; one long-lived process accumulates any un-silenced leak |
| D2 | Concurrency = 1 (single worker on `imports` queue) | `receipt_sequence` lock serializes every payment regardless → parallel buys ~no throughput, adds deadlock surface | Parallel chunks — deferred; escape hatches documented (§3) |
| D3 | Instrumentation kill-switch in a shared trait, called per job, Telescope restored in `finally` | Single source of truth; strictly scoped to the import tick; no blinding of later jobs on the same worker | Disable globally / per-row unset of containers — too broad / fragile |
| D4 | Parse once → cache normalized rows to a run-scoped JSON file; chunks read their slice | Avoids K× whole-sheet `Excel::toArray`; deterministic cleanup; small footprint | Re-parse per chunk (K× read cost); Cache facade (Redis bloat) |
| D5 | New terminal status `completed_with_errors` + first-class `failed_count`/`succeeded_count` | Distinct status is unambiguous for every status consumer; tiny additive blast radius (one FE consumer) | `ready` + report-only counts — re-creates the silent-swallow trap |
| D6 | `phase` as a real column + mirrored in `progress.phase` | Column = canonical/ops-readable; mirror = single FE read of `progress`; `total` set early kills 0/0 | Phase only in JSON — harder for ops; phase only in column — extra FE field plumbing |
| D7 | Pass `importRunId` (not the model) into chunk/finalize jobs | Tiny payloads across K jobs; tenant-scoped `findOrFail` re-fetch is auto-isolated and 404-safe | Pass `SerializesModels` model — re-queries anyway, bloats payloads |
| D8 | Progress/report writes = locked RMW, once per chunk | Concurrency-safe for a future worker bump; kills the ~1000 per-row UPDATE defect | Per-row save (current) — the defect; unlocked RMW — future race |
| D9 | FE `startedRef` gating the effect body | Stops both StrictMode `POST /execute` fires at the source | `cancelled`-only — only hides the result, both POSTs still fire |

---

## 9. Risks & assumptions for downstream phases

- **R1 (medium):** exact `laravel-sql-logger` runtime off-switch keys — verify the installed
  package's config namespace in `sdd-apply`; the principle is fixed, the keys are a detail.
- **R2 (medium):** whole-file json load in chunk jobs at 10k+ rows — fine at 1000; NDJSON slice read
  is the documented follow-up. Design A is the memory fallback.
- **R3 (high, must-test):** tenant isolation across batched jobs — add the cross-tenant feature test
  (§6). This is the one place a regression silently leaks data.
- **R4 (low):** `->finally` finalizer must be robust to a never-dispatched batch (coordinator failed
  before `Bus::batch`) — the coordinator's own `catch` sets `failed` directly in that case, so the
  finalizer is only the post-batch path.
- **A1:** A single dedicated `imports` queue worker exists in the deployment. If the deployment runs
  one shared worker, concurrency=1 still holds as long as that worker processes `imports` serially;
  document the queue/worker requirement for ops.
- **A2:** `QUEUE_CONNECTION` is NOT `sync` in any real environment (the original inline-execution
  defect was an env fix, out of scope). With `Bus::batch` on `sync`, the batch runs inline — the
  design assumes a real queue driver (redis/database).
