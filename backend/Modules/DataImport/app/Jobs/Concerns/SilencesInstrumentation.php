<?php

namespace Modules\DataImport\Jobs\Concerns;

use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;

/**
 * SilencesInstrumentation — disables per-query memory accumulation for an import job tick.
 *
 * Root cause of the OOM-at-44-rows: every per-query instrumentation accumulator retains
 * an entry per query across the whole loop. There are THREE accumulators in this app:
 *
 *  1. The Eloquent/DB query log — an unbounded in-memory array while enabled.
 *  2. Laravel Telescope (QueryWatcher + ModelWatcher + EventWatcher + …).
 *  3. overtrue/laravel-query-logger's listener — logs every QueryExecuted event when
 *     `logging.query.enabled` is true.
 *
 * IMPORTANT (verified at apply time — design risk R1): the installed package is
 * `overtrue/laravel-query-logger` (config namespace `logging.query.*`), NOT
 * `antoniors/laravel-sql-logger`. Its listener is registered ONCE at boot, so flipping
 * config('logging.query.enabled' => false) at runtime does NOT detach it. BUT the
 * listener re-reads `logging.query.except` and `logging.query.slower_than` PER QUERY and
 * returns early when the SQL matches `except` or is faster than `slower_than`. So the
 * documented runtime off-switch is to set `except => ['*']` (every query matches) — this
 * is the off-switch the listener actually honors, with no fragile listener surgery.
 *
 * Telescope is stopped via stopRecording() (covers its query watcher AND every other
 * watcher). Everything is restored in the job's `finally` so later jobs on the same
 * long-lived worker stay instrumented.
 *
 * Usage in a job's handle():
 *
 *   $this->silenceInstrumentation();
 *   try {
 *       // ... job body ...
 *   } finally {
 *       $this->restoreInstrumentation();
 *   }
 *
 * restoreInstrumentation() is IDEMPOTENT and must ALSO be called from the job's failed()
 * hook: a hard failure (OOM, the queue $timeout SIGALRM, exhausted tries, fatal Throwable)
 * does NOT unwind through the handle() try/finally on a long-lived queue:work / Octane
 * worker — the worker traps it and calls failed() on a fresh frame. Calling restore there
 * guarantees the kill-switch (Telescope off + query-logger off) never leaks past the import
 * tick and blinds later jobs on the same worker. Calling it twice (finally + failed) or
 * without a prior silence is a safe no-op.
 */
trait SilencesInstrumentation
{
    /**
     * Whether Telescope was recording before we silenced it (so we only restart
     * it in finally if it was on — never blind/unblind it incorrectly).
     */
    private bool $telescopeWasRecording = false;

    /**
     * Original `logging.query.*` flags, restored in finally.
     *
     * @var array<string, mixed>
     */
    private array $originalQueryLoggerConfig = [];

    private bool $instrumentationSilenced = false;

    /**
     * Disable per-query memory accumulation for THIS job tick.
     */
    protected function silenceInstrumentation(): void
    {
        // 1. Eloquent/DB query log (unbounded array in memory while enabled).
        DB::connection()->disableQueryLog();

        // 2. Telescope — guarded so non-Telescope environments are unaffected.
        if (class_exists(Telescope::class)) {
            $this->telescopeWasRecording = Telescope::isRecording();
            Telescope::stopRecording();
        }

        // 3. overtrue/laravel-query-logger — its boot-registered listener re-reads
        //    `except`/`slower_than` per query, so force every query to short-circuit
        //    (except '*' matches all SQL; slower_than = PHP_INT_MAX never triggers).
        $this->originalQueryLoggerConfig = [
            'logging.query.enabled' => config('logging.query.enabled'),
            'logging.query.except' => config('logging.query.except'),
            'logging.query.slower_than' => config('logging.query.slower_than'),
        ];

        config([
            'logging.query.enabled' => false,
            'logging.query.except' => ['*'],
            'logging.query.slower_than' => PHP_INT_MAX,
        ]);

        $this->instrumentationSilenced = true;
    }

    /**
     * Re-enable instrumentation that we silenced, so a long-lived worker keeps
     * recording for unrelated jobs that run after this import job tick.
     *
     * Safe to call even if silenceInstrumentation() was not called (no-op).
     */
    protected function restoreInstrumentation(): void
    {
        if (! $this->instrumentationSilenced) {
            return;
        }

        // Restore the overtrue query-logger config flags exactly as they were.
        config($this->originalQueryLoggerConfig);
        $this->originalQueryLoggerConfig = [];

        // Restart Telescope only if it was recording before we silenced it.
        if ($this->telescopeWasRecording && class_exists(Telescope::class)) {
            Telescope::startRecording();
        }

        $this->telescopeWasRecording = false;
        $this->instrumentationSilenced = false;
    }
}
