<?php

namespace Modules\DataImport\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\DataImport\Jobs\Concerns\MarksImportRunFailed;
use Modules\DataImport\Jobs\Concerns\SilencesInstrumentation;
use Modules\DataImport\Models\ImportRun;
use Modules\DataImport\Services\WorkbookReader;
use RuntimeException;
use Throwable;

/**
 * RunImportJob — COORDINATOR for an asynchronous payment import.
 *
 * It does NOT write any domain rows. Its job is to:
 *  1. Silence instrumentation (kill-switch — see SilencesInstrumentation).
 *  2. Mark the run processing / phase=reading_file.
 *  3. Parse the workbook ONCE via WorkbookReader (Excel::toArray loads the whole sheet).
 *  4. Persist the normalized rows to a run-scoped JSON cache file so chunk jobs read
 *     only their slice without re-paying the whole-sheet parse cost.
 *  5. Set progress.total EARLY (before any chunk runs) + phase=processing.
 *  6. Dispatch a Bus::batch of ProcessImportChunkJob (one per CHUNK_SIZE slice),
 *     ->allowFailures() so one bad chunk does not kill the batch, with a
 *     FinalizeImportJob dispatched via ->finally() in every settle path.
 *
 * Concurrency is governed by the worker count on the 'imports' queue. v1 runs a single
 * worker there ⇒ effective concurrency = 1 (the receipt_sequence lock serializes every
 * payment regardless — see design §3).
 *
 * QueueTenancyBootstrapper carries tenant_id into the queue payload so tenancy is
 * automatically initialized when this job (and each chunk/finalizer) runs.
 *
 * If the coordinator throws BEFORE the batch is dispatched (e.g. a malformed workbook),
 * its own catch sets status=failed directly — the finalizer is only the post-batch path.
 */
class RunImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use MarksImportRunFailed;
    use Queueable;
    use SerializesModels;
    use SilencesInstrumentation;

    /**
     * Rows per chunk. Exposed as a const so it is tunable without a contract change.
     * 200 keeps the per-tick working set small while halving batch bookkeeping vs 100;
     * a 1000-row file is exactly 5 chunks.
     */
    public const CHUNK_SIZE = 200;

    /**
     * Dedicated queue for import jobs. Run a single worker on it for v1 concurrency=1.
     */
    public const QUEUE = 'imports';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly string $importRunId,
        private readonly string $filePath,
    ) {}

    public function handle(WorkbookReader $reader): void
    {
        $this->silenceInstrumentation();

        try {
            // BelongsToTenant + the re-initialized tenant scope keep this auto-scoped;
            // a cross-tenant id simply 404s, which is the safe failure.
            $run = ImportRun::find($this->importRunId);

            if (! $run) {
                return;
            }

            try {
                $run->status = ImportRun::STATUS_PROCESSING;
                $run->phase = ImportRun::PHASE_READING_FILE;
                $run->progress = ['processed' => 0, 'total' => 0, 'phase' => ImportRun::PHASE_READING_FILE];
                $run->report = ImportRun::emptyReport();
                $run->save();

                // ── Parse the workbook ONCE ──────────────────────────────────────
                $rows = $reader->parse($this->filePath);
                $total = $rows->count();

                // Persist normalized rows as a list of {_row, ...fields} records so each
                // chunk reads its slice by offset/length and keeps the spreadsheet row number.
                $records = [];
                foreach ($rows as $rowNumber => $rowData) {
                    $records[] = array_merge(['_row' => $rowNumber], $rowData);
                }

                // Guard the row-slice cache write. If the JSON encode fails or Storage::put
                // reports false, the chunk jobs would silently load an EMPTY slice and the
                // finalizer would falsely report `ready` with 0 payments. Fail loudly instead.
                $cachePath = self::cachePath((string) tenant('id'), $run->id);
                $encoded = json_encode($records, JSON_UNESCAPED_UNICODE);

                if ($encoded === false) {
                    throw new RuntimeException(
                        'No se pudo serializar el archivo importado: '.json_last_error_msg()
                    );
                }

                if (Storage::put($cachePath, $encoded) === false) {
                    throw new RuntimeException(
                        "No se pudo escribir el archivo temporal de importación ({$cachePath})."
                    );
                }

                // ── Set total EARLY + phase=processing (before any chunk runs) ────
                $run->phase = ImportRun::PHASE_PROCESSING;
                $run->progress = ['processed' => 0, 'total' => $total, 'phase' => ImportRun::PHASE_PROCESSING];
                $report = $run->report ?? ImportRun::emptyReport();
                $report['total'] = $total;
                $run->report = $report;
                $run->save();

                // ── Build chunk descriptors over the cached record list ──────────
                $chunks = [];
                for ($offset = 0; $offset < $total; $offset += self::CHUNK_SIZE) {
                    $chunks[] = new ProcessImportChunkJob(
                        importRunId: $run->id,
                        offset: $offset,
                        length: self::CHUNK_SIZE,
                    );
                }

                // Empty file: no chunks. Still finalize so the run reaches a terminal state.
                if (empty($chunks)) {
                    FinalizeImportJob::dispatch($run->id)->onQueue(self::QUEUE);

                    return;
                }

                $runId = $run->id;

                Bus::batch($chunks)
                    ->name("import:{$runId}")
                    ->allowFailures()
                    ->onQueue(self::QUEUE)
                    ->finally(function (Batch $batch) use ($runId): void {
                        FinalizeImportJob::dispatch($runId)->onQueue(self::QUEUE);
                    })
                    ->dispatch();
            } catch (Throwable $e) {
                // Coordinator failed BEFORE/DURING dispatch (e.g. malformed workbook, an
                // unwritable row cache, or a throw inside the persist below). Set failed
                // directly — the finalizer never runs because the batch never settled.
                Log::error('[RunImportJob] Coordinator failed', [
                    'import_run_id' => $run->id,
                    'file_path' => $this->filePath,
                    'error' => $e->getMessage(),
                ]);

                // markRunFailed() is a LOCKED, swallow-proof read-modify-write: a save()
                // exception here is logged, never re-thrown, so the catch can never itself
                // throw and lose the failure. The job's failed() hook is the further
                // backstop for a hard failure that bypasses this try entirely.
                $this->markRunFailed($run->id, $e);
            }
        } finally {
            $this->restoreInstrumentation();
        }
    }

    /**
     * Queue failure hook — fires on a hard failure (OOM, $timeout SIGALRM, exhausted
     * tries, fatal Throwable) that bypasses the handle() try/finally on a long-lived
     * worker. Restores instrumentation so the kill-switch never poisons later jobs, and
     * pushes the run to a terminal failed state so it is never left stuck non-terminal.
     */
    public function failed(Throwable $e): void
    {
        $this->restoreInstrumentation();
        $this->markRunFailed($this->importRunId, $e);
    }

    /**
     * Run-scoped JSON cache path for the normalized rows. Tenant-namespaced.
     */
    public static function cachePath(string $tenantId, string $importRunId): string
    {
        return "imports/{$tenantId}/{$importRunId}.rows.json";
    }
}
