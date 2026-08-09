<?php

namespace Modules\DataImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Collections\Services\ChargeStatusService;
use Modules\DataImport\Jobs\Concerns\MarksImportRunFailed;
use Modules\DataImport\Jobs\Concerns\SilencesInstrumentation;
use Modules\DataImport\Models\ImportRun;
use Modules\Treasury\Models\BankAccount;
use Throwable;

/**
 * FinalizeImportJob — runs ONCE after the chunk batch settles (via ->finally on the
 * batch, so it fires on full success, partial failure with allowFailures(), OR cancel).
 *
 * It is the single honest place for the terminal outcome and the aggregate recompute:
 *  1. Silence instrumentation; phase=finalizing.
 *  2. Recompute unit aggregates ONCE for every affected unit (NOT per row, NOT per
 *     chunk) — the worst-wins ChargeStatusService batch method, in a transaction.
 *  3. Compute the terminal status from accumulated counters:
 *     - failed                 → nothing applied AND nothing skipped (no usable outcome).
 *     - completed_with_errors  → failed_count > 0 (≥1 row threw during execute).
 *     - ready                  → failed_count === 0 (clean; idempotency skips are NOT errors).
 *  4. Resolve bank_balance_delta from the accumulated per-account deltas (tenant-scoped).
 *  5. Strip the interim accumulator keys, set succeeded_count, phase=done, persist
 *     status + report under a row lock.
 *  6. Best-effort cleanup of the rows cache + the uploaded file.
 */
class FinalizeImportJob implements ShouldQueue
{
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
    ) {}

    public function handle(ChargeStatusService $chargeStatusService): void
    {
        $this->silenceInstrumentation();

        try {
            $run = ImportRun::find($this->importRunId);

            if (! $run) {
                return;
            }

            // Re-entry guard: a run that has reached ANY terminal state (ready,
            // completed_with_errors, or failed) is already finalized and must not be
            // re-finalized by a retried/duplicate ->finally finalizer. The interim
            // bank_deltas / affected_unit_ids accumulators are stripped on the first
            // finalize, so a second pass would recompute bank_balance_delta from an
            // empty array and LOSE it. Bail out and keep the existing outcome intact.
            if (in_array($run->status, ImportRun::TERMINAL_STATUSES, true)) {
                return;
            }

            $run->phase = ImportRun::PHASE_FINALIZING;
            $progress = $run->progress ?? ['processed' => 0, 'total' => 0];
            $progress['phase'] = ImportRun::PHASE_FINALIZING;
            $run->progress = $progress;
            $run->save();

            $report = $run->report ?? ImportRun::emptyReport();

            // ── Step 1: single aggregate recompute for all affected units ────────
            $affectedUnitIds = array_values(array_unique($report['affected_unit_ids'] ?? []));

            if (! empty($affectedUnitIds)) {
                try {
                    DB::transaction(
                        fn () => $chargeStatusService->recomputeUnitAggregates($affectedUnitIds)
                    );
                } catch (Throwable $e) {
                    // Non-fatal: the payments already landed; the aggregate is a safety net.
                    Log::warning('[FinalizeImportJob] recomputeUnitAggregates failed', [
                        'import_run_id' => $run->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ── Step 2: resolve bank_balance_delta from interim per-account deltas ─
            $report['bank_balance_delta'] = $this->buildBankBalanceDelta($report['bank_deltas'] ?? []);

            // ── Step 3: terminal status from accumulated counters ────────────────
            $registered = (int) ($report['payments_registered'] ?? 0);
            $skipped = (int) ($report['payments_skipped'] ?? 0);
            $failed = (int) ($report['failed_count'] ?? 0);

            $report['succeeded_count'] = $registered;

            if ($registered === 0 && $skipped === 0 && $failed > 0) {
                // Nothing usable was produced — a hard failure.
                $status = ImportRun::STATUS_FAILED;
            } elseif ($failed > 0) {
                $status = ImportRun::STATUS_COMPLETED_WITH_ERRORS;
            } else {
                $status = ImportRun::STATUS_READY;
            }

            // ── Step 4: strip interim accumulators from the final stored report ──
            unset($report['bank_deltas'], $report['affected_unit_ids']);

            // ── Step 5: phase=done, processed=total (attempted), persist ─────────
            $this->persistTerminal($run->id, $status, $report);

            // ── Step 6: best-effort cleanup ──────────────────────────────────────
            $this->cleanup($run->id, $run->file_path);
        } finally {
            $this->restoreInstrumentation();
        }
    }

    /**
     * Queue failure hook — fires on a hard failure (OOM, $timeout SIGALRM, exhausted
     * tries, fatal Throwable) that bypasses the handle() try/finally on a long-lived
     * worker. Restores instrumentation and marks the run terminal-failed so the run is
     * never left stuck in `finalizing`. markRunFailed() is idempotent: if the terminal
     * status was already persisted before the failure, the existing outcome is kept.
     */
    public function failed(Throwable $e): void
    {
        $this->restoreInstrumentation();
        $this->markRunFailed($this->importRunId, $e);
    }

    /**
     * Persist the terminal status + final report under a row lock.
     *
     * @param  array<string, mixed>  $report
     */
    private function persistTerminal(string $importRunId, string $status, array $report): void
    {
        DB::transaction(function () use ($importRunId, $status, $report): void {
            $locked = ImportRun::where('id', $importRunId)->lockForUpdate()->firstOrFail();

            $progress = $locked->progress ?? ['processed' => 0, 'total' => 0];
            // processed = attempted; on a settled run it equals total.
            $progress['processed'] = $progress['total'] ?? ($progress['processed'] ?? 0);
            $progress['phase'] = ImportRun::PHASE_DONE;

            $locked->status = $status;
            $locked->phase = ImportRun::PHASE_DONE;
            $locked->progress = $progress;
            $locked->report = $report;
            $locked->save();
        });
    }

    /**
     * Build the bank_balance_delta array by resolving account names (tenant-scoped).
     *
     * @param  array<string, int>  $deltas  bank_account_id => minor units
     * @return list<array{bank_account: string, delta: int}>
     */
    private function buildBankBalanceDelta(array $deltas): array
    {
        if (empty($deltas)) {
            return [];
        }

        $result = [];

        foreach ($deltas as $accountId => $delta) {
            // Scope explicitly to the tenant — find() bypasses TenantScope.
            $account = BankAccount::where('id', $accountId)
                ->where('tenant_id', tenant('id'))
                ->first();

            $result[] = [
                'bank_account' => $account?->name ?? $accountId,
                'delta' => $delta,
            ];
        }

        return $result;
    }

    /**
     * Delete the rows cache + the original upload file (best-effort, logged on failure).
     */
    private function cleanup(string $importRunId, ?string $filePath): void
    {
        try {
            $cachePath = RunImportJob::cachePath((string) tenant('id'), $importRunId);

            if (Storage::exists($cachePath)) {
                Storage::delete($cachePath);
            }

            if ($filePath !== null && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
        } catch (Throwable $e) {
            Log::warning('[FinalizeImportJob] cleanup failed', [
                'import_run_id' => $importRunId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
