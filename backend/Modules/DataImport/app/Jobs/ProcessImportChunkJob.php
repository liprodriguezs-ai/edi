<?php

namespace Modules\DataImport\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\DataImport\Jobs\Concerns\MarksImportRunFailed;
use Modules\DataImport\Jobs\Concerns\SilencesInstrumentation;
use Modules\DataImport\Models\ImportRun;
use Modules\DataImport\Services\ReferenceResolver;
use Modules\DataImport\Services\RowImporter;
use Modules\DataImport\Services\RowResult;
use Modules\SubscriptionPlan\Exceptions\UnitLimitExceededException;
use RuntimeException;
use Throwable;

/**
 * ProcessImportChunkJob — executes one CHUNK_SIZE slice of an import run.
 *
 * Each chunk job is a fresh worker tick, so memory is reclaimed at the process
 * boundary between chunks (the strongest memory guarantee — design §0/D1).
 *
 * Per-row flow (UNCHANGED domain path):
 *  - RowImporter::execute() inside a per-row DB::transaction.
 *  - idempotency (folio + natural key) + PaymentService FIFO + minor-units money
 *    + receipt_sequence folio minting all happen inside that path.
 *
 * A row that throws is caught per-row → counted as a `failed` row with an error in
 * report.rows; OTHER rows in the same chunk keep processing (no full-chunk crash).
 * This is the lightweight defensive guard contract — the full /validate dry-run is
 * NOT repeated here (spec REQ-08).
 *
 * Progress + the additive partial report are persisted ONCE per chunk via a locked
 * read-modify-write on the import_run row (NOT per row — design §2.4).
 *
 * QueueTenancyBootstrapper re-initializes tenancy from the job payload, so the
 * tenant-scoped ImportRun::find + every domain query is scoped to the run's tenant.
 */
class ProcessImportChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use MarksImportRunFailed;
    use Queueable;
    use SerializesModels;
    use SilencesInstrumentation;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly string $importRunId,
        private readonly int $offset,
        private readonly int $length,
    ) {}

    public function handle(RowImporter $rowImporter): void
    {
        $this->silenceInstrumentation();

        try {
            // If the batch was cancelled, do not start new work.
            if ($this->batch()?->cancelled()) {
                return;
            }

            $run = ImportRun::find($this->importRunId);

            if (! $run) {
                return;
            }

            $records = $this->loadSlice($run->id);

            if (empty($records)) {
                return;
            }

            $counters = [
                'units_created' => 0,
                'residents_created' => 0,
                'payments_registered' => 0,
                'payments_skipped' => 0,
                'failed_count' => 0,
                'bank_deltas' => [],          // bank_account_id => minor units
                'affected_unit_ids' => [],    // list<string>
            ];
            $issues = [];
            $rowsHandled = 0;
            $sinceTick = 0;

            // Per-chunk resolver: each chunk is a fresh process, so memoization is
            // per-chunk. The auto-create paths are query-first + idempotent, so a
            // type/account created by an earlier chunk is FOUND (not re-created).
            $resolver = new ReferenceResolver;

            foreach ($records as $record) {
                $rowsHandled++;

                // Smooth progress: bump processed every PROGRESS_TICK rows so the UI bar
                // advances steadily (not once per chunk), without the per-row UPDATE cost.
                $sinceTick++;
                if ($sinceTick >= self::PROGRESS_TICK) {
                    $this->bumpProcessed($run->id, $sinceTick);
                    $sinceTick = 0;
                }

                $rowNumber = (int) ($record['_row'] ?? 0);
                $rowData = $record;
                unset($rowData['_row']);

                // Lightweight defensive guard (spec REQ-08): the full /validate dry-run
                // is NOT repeated here — execution trusts that /validate gated blocked
                // rows. We only assert the minimal mandatory fields are present so a row
                // that slipped past /validate is counted as `failed` (with an error in
                // report.rows) instead of silently producing a $0 / malformed payment.
                $guardError = $this->defensiveGuardError($rowData);

                if ($guardError !== null) {
                    $counters['failed_count']++;
                    $issues[] = [
                        'row' => $rowNumber,
                        'severity' => 'error',
                        'column' => 'fila',
                        'message' => $guardError,
                    ];

                    continue;
                }

                try {
                    $rowResult = DB::transaction(function () use ($rowData, $rowNumber, $resolver, $rowImporter): RowResult {
                        return $rowImporter->execute($rowData, $rowNumber, $resolver);
                    });

                    if ($rowResult->unitCreated) {
                        $counters['units_created']++;
                    }

                    if ($rowResult->residentCreated) {
                        $counters['residents_created']++;
                    }

                    if ($rowResult->skipped) {
                        $counters['payments_skipped']++;
                    } elseif ($rowResult->paymentRegistered) {
                        $counters['payments_registered']++;

                        if ($rowResult->bankAccountId !== null) {
                            $counters['bank_deltas'][$rowResult->bankAccountId] =
                                ($counters['bank_deltas'][$rowResult->bankAccountId] ?? 0) + $rowResult->totalAmount;
                        }
                    }

                    if ($rowResult->unitId !== null) {
                        $counters['affected_unit_ids'][] = $rowResult->unitId;
                    }

                    // Drop the per-row "… se creó automáticamente" auto-creation warnings:
                    // they flood the report (one per new unit + resident) and add nothing
                    // the summary counts (units_created/residents_created) don't already
                    // convey. Every other issue (idempotency skips, catalog warnings,
                    // failures) is kept.
                    foreach ($rowResult->issues as $issue) {
                        if (! $this->isAutoCreateWarning($issue)) {
                            $issues[] = $issue;
                        }
                    }
                } catch (Throwable $e) {
                    // Defensive guard: a single row failure must NOT crash the chunk.
                    $counters['failed_count']++;
                    $issues[] = [
                        'row' => $rowNumber,
                        'severity' => 'error',
                        'column' => 'fila',
                        // The plan's unit limit is an EXPECTED business block with a
                        // self-explanatory message ("Tu plan Básico permite hasta N
                        // unidades…"); prefixing it with "Error inesperado" told the
                        // admin the importer had crashed. Only genuinely unexpected
                        // throwables keep the prefix.
                        'message' => $e instanceof UnitLimitExceededException
                            ? $e->getMessage()
                            : 'Error inesperado al procesar fila: '.$e->getMessage(),
                    ];
                }
            }

            $this->persistChunkProgress($run->id, $counters, $issues, $sinceTick);
        } finally {
            $this->restoreInstrumentation();
        }
    }

    /**
     * Queue failure hook — fires on a hard failure (OOM, $timeout SIGALRM, exhausted
     * tries, fatal Throwable, or a thrown missing-cache-slice) that bypasses the
     * handle() try/finally on a long-lived worker. Restores instrumentation and marks
     * the run terminal-failed so a chunk crash never leaves the run stuck in `processing`.
     */
    public function failed(Throwable $e): void
    {
        $this->restoreInstrumentation();
        $this->markRunFailed($this->importRunId, $e);
    }

    /**
     * Minimal defensive guard for a row reaching the execution path.
     *
     * This is NOT the /validate dry-run (spec REQ-08) — it only asserts the mandatory
     * fields are present and the amount is a positive number, mirroring the hard
     * (severity=error) checks RowImporter::validate() applies. A row failing this is
     * counted as `failed`; it never crashes the chunk.
     *
     * @param  array<string, mixed>  $row
     * @return string|null A Spanish error message, or null when the row passes.
     */
    private function defensiveGuardError(array $row): ?string
    {
        $required = [
            'tipo_unidad' => 'El tipo de unidad es obligatorio.',
            'numero' => 'El número de unidad es obligatorio.',
            'nombre' => 'El nombre del residente es obligatorio.',
            'apellido_paterno' => 'El apellido paterno es obligatorio.',
            'fecha_pago' => 'La fecha de pago es obligatoria.',
        ];

        foreach ($required as $field => $message) {
            if (! isset($row[$field]) || $row[$field] === '' || $row[$field] === null) {
                return $message;
            }
        }

        if (! isset($row['monto']) || ! is_numeric($row['monto']) || (float) $row['monto'] <= 0) {
            return 'El monto es obligatorio y debe ser mayor que 0.';
        }

        return null;
    }

    /**
     * Load this chunk's slice from the run-scoped JSON cache.
     *
     * For v1 simplicity the whole file is decoded and sliced in memory — acceptable
     * at 1000 rows with instrumentation off (design §2.2; NDJSON slice read is the
     * documented 10k+ follow-up).
     *
     * A chunk only ever runs because the coordinator wrote this cache after a non-empty
     * parse, so a MISSING or UNREADABLE cache here is a hard fault — NOT a legitimately
     * empty slice. Throwing routes it to failed() so the run fails loudly instead of the
     * chunk silently processing zero rows and the finalizer falsely reporting `ready`.
     *
     * @return list<array<string, mixed>>
     */
    private function loadSlice(string $importRunId): array
    {
        $cachePath = RunImportJob::cachePath((string) tenant('id'), $importRunId);

        if (! Storage::exists($cachePath)) {
            throw new RuntimeException(
                "El archivo temporal de importación no existe ({$cachePath})."
            );
        }

        $decoded = json_decode((string) Storage::get($cachePath), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                "El archivo temporal de importación está corrupto o ilegible ({$cachePath})."
            );
        }

        // A legitimately empty slice (offset at/after the end of the record list) is fine
        // and returns []; the caller short-circuits without treating it as an error.
        return array_slice($decoded, $this->offset, $this->length);
    }

    /** Bump import_run.progress.processed every N rows so the UI bar advances smoothly. */
    private const PROGRESS_TICK = 50;

    /**
     * Auto-creation warnings ("… no existía — se creó automáticamente") are emitted once
     * per new unit / resident / unit_type / bank account. They are noise in the final
     * report (the created-counts already summarize them), so they are excluded from
     * report.rows. The auto-creation itself still happens; counts are unaffected.
     *
     * @param  array{row:int,severity:string,column:string,message:string}  $issue
     */
    private function isAutoCreateWarning(array $issue): bool
    {
        return ($issue['severity'] ?? '') === 'warning'
            && str_contains($issue['message'] ?? '', 'se creó automáticamente');
    }

    /**
     * Lightweight progress tick: increment import_run.progress.processed by $delta
     * under a row lock, WITHOUT touching the report/counters. Called every
     * PROGRESS_TICK rows so the bar advances steadily; the per-chunk persist still
     * merges counters + the remaining processed delta.
     */
    private function bumpProcessed(string $importRunId, int $delta): void
    {
        if ($delta <= 0) {
            return;
        }

        DB::transaction(function () use ($importRunId, $delta): void {
            $locked = ImportRun::where('id', $importRunId)->lockForUpdate()->firstOrFail();
            $progress = $locked->progress ?? [];
            $progress['processed'] = ($progress['processed'] ?? 0) + $delta;
            $progress['total'] = $progress['total'] ?? 0;
            $progress['phase'] = ImportRun::PHASE_PROCESSING;
            $locked->progress = $progress;
            $locked->save();
        });
    }

    /**
     * Persist progress + the additive partial report for this chunk under a row lock.
     *
     * lockForUpdate() serializes the read-modify-write so a future concurrent chunk
     * cannot clobber another's `processed` or counters (concurrency-safe for a later
     * worker bump). One write per chunk — kills the old ~1000 per-row UPDATE defect.
     *
     * @param  array{units_created:int,residents_created:int,payments_registered:int,payments_skipped:int,failed_count:int,bank_deltas:array<string,int>,affected_unit_ids:list<string>}  $counters
     * @param  list<array{row:int,severity:string,column:string,message:string}>  $issues
     */
    private function persistChunkProgress(string $importRunId, array $counters, array $issues, int $processedDelta): void
    {
        DB::transaction(function () use ($importRunId, $counters, $issues, $processedDelta): void {
            $locked = ImportRun::where('id', $importRunId)->lockForUpdate()->firstOrFail();

            // progress: monotonic increment (remaining rows since the last in-chunk tick)
            $progress = $locked->progress ?? [];
            $progress['processed'] = ($progress['processed'] ?? 0) + $processedDelta;
            $progress['total'] = $progress['total'] ?? 0;
            $progress['phase'] = ImportRun::PHASE_PROCESSING;
            $locked->progress = $progress;

            // report: additive merge of counters + append issues + accumulate deltas
            $report = $locked->report ?? ImportRun::emptyReport();
            $report['units_created'] = ($report['units_created'] ?? 0) + $counters['units_created'];
            $report['residents_created'] = ($report['residents_created'] ?? 0) + $counters['residents_created'];
            $report['payments_registered'] = ($report['payments_registered'] ?? 0) + $counters['payments_registered'];
            $report['payments_skipped'] = ($report['payments_skipped'] ?? 0) + $counters['payments_skipped'];
            $report['failed_count'] = ($report['failed_count'] ?? 0) + $counters['failed_count'];

            // TODO(perf, low): report.rows is rewritten in full on every chunk's locked
            // RMW, so the cumulative bytes written scale ~O(rows²) across a large import.
            // This is inherent to merging into a single JSON column; a true fix needs a
            // separate append-only issue store (e.g. an import_run_issue child table or
            // NDJSON sidecar) — out of scope for v1 and deliberately NOT over-engineered
            // here. Acceptable at the documented ≤1000-row ceiling; revisit for 10k+.
            $report['rows'] = array_merge($report['rows'] ?? [], $issues);

            $bankDeltas = $report['bank_deltas'] ?? [];
            foreach ($counters['bank_deltas'] as $accId => $delta) {
                $bankDeltas[$accId] = ($bankDeltas[$accId] ?? 0) + $delta;
            }
            $report['bank_deltas'] = $bankDeltas;

            $report['affected_unit_ids'] = array_values(array_unique(
                array_merge($report['affected_unit_ids'] ?? [], $counters['affected_unit_ids'])
            ));

            $locked->report = $report;
            $locked->save();
        });
    }
}
