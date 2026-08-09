<?php

namespace Modules\DataImport\Jobs\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\DataImport\Models\ImportRun;
use Throwable;

/**
 * MarksImportRunFailed — the single, reusable terminal-failure path for every import job.
 *
 * WHY THIS EXISTS (review fix — CRITICAL):
 * The three import jobs run with tries=1 on a long-lived queue:work / Octane worker.
 * A hard failure (OOM, the queue $timeout SIGALRM, or a fatal/uncaught Throwable) does
 * NOT unwind through the job's try/finally — the worker traps it and invokes the job's
 * failed() hook on a fresh call frame. Without a failed() hook the run is left stuck in a
 * non-terminal state (pending / reading_file / processing / finalizing) forever, the FE
 * poller spins, and the instrumentation kill-switch (Telescope off + query-logger off)
 * stays engaged, blinding every later job that shares the worker.
 *
 * This concern provides markRunFailed() which the failed() hook of EACH job calls to push
 * the run to the terminal STATUS_FAILED state with the error message. It is:
 *  - LOCKED: a lockForUpdate read-modify-write so it cannot race a concurrent chunk write.
 *  - TENANT-SAFE: QueueTenancyBootstrapper has already re-initialized tenancy from the job
 *    payload, so the BelongsToTenant TenantScope auto-scopes the lookup; a cross-tenant id
 *    simply finds nothing (safe no-op).
 *  - IDEMPOTENT: if the run already reached a terminal state (ready / completed_with_errors
 *    / failed) it leaves the existing outcome intact — a late SIGALRM on a finalizer that
 *    already persisted `ready` must not be downgraded to `failed`.
 *  - SWALLOW-PROOF: a failure to persist is logged, never re-thrown, so the failed() hook
 *    itself cannot raise a second exception inside the worker's failure handling.
 */
trait MarksImportRunFailed
{
    /**
     * Mark the import run terminal-failed from a job's failed() hook.
     *
     * Safe to call multiple times and from any of the three jobs.
     */
    protected function markRunFailed(string $importRunId, Throwable $e): void
    {
        try {
            DB::transaction(function () use ($importRunId, $e): void {
                $run = ImportRun::where('id', $importRunId)->lockForUpdate()->first();

                if (! $run) {
                    return;
                }

                // Do not overwrite an already-terminal outcome (a late timeout on a
                // finalizer that already persisted ready/completed_with_errors must not
                // be downgraded to failed).
                if (in_array($run->status, ImportRun::TERMINAL_STATUSES, true)) {
                    return;
                }

                $run->status = ImportRun::STATUS_FAILED;
                $run->phase = ImportRun::PHASE_DONE;
                $run->error = $e->getMessage();

                $progress = $run->progress ?? ['processed' => 0, 'total' => 0];
                $progress['phase'] = ImportRun::PHASE_DONE;
                $run->progress = $progress;

                $run->save();
            });
        } catch (Throwable $persistError) {
            // The failed() hook must never raise a second exception. Log and move on.
            Log::error('[ImportRun] failed() could not persist terminal failed state', [
                'import_run_id' => $importRunId,
                'original_error' => $e->getMessage(),
                'persist_error' => $persistError->getMessage(),
            ]);
        }
    }
}
