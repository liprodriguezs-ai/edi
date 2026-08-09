<?php

namespace Modules\Collections\Services;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargePolicy;

/**
 * PeriodChainService — the SINGLE implementation of "which periods does this policy still
 * owe, and how are those periods materialized".
 *
 * ANCHORED BILLING PERIODS: a policy's periods are CHAINED (start(n+1) = end(n) + 1 day),
 * never resolved from a calendar-absolute grid. Two callers need that chain and they must
 * behave identically, so it lives here instead of inside either of them:
 *
 *  - GenerateMonthlyChargesCommand (daily cron): seeds the chain at MAX(due_date) + 1 day
 *    of the policy's last settled batch (or valid_from for a virgin policy) and walks it
 *    forward for EVERY active policy of EVERY tenant.
 *  - ChargePolicyService::create (HTTP): seeds the chain right after the policy's FIRST
 *    anchored period and walks it forward for THAT policy ONLY — so a retroactive
 *    valid_from produces its whole backlog at creation instead of a single lonely period
 *    the admin then has to chase with a manual cron run (which would also generate other
 *    policies' periods as a side effect).
 *
 * Both callers get the same stop conditions (cap / valid_to / not-yet-due), the same
 * idempotency recovery, and the same consolidated-notification rule.
 */
class PeriodChainService
{
    public function __construct(
        private readonly ChargeGenerationService $generationService,
        private readonly PeriodicityScheduleResolver $resolver,
    ) {}

    /**
     * Walk the chain of periods due for $policy, starting at $seed, stopping at the first
     * of: cap reached, valid_to passed, or the next period has not started firing yet
     * (today < issuedAt — which also covers periodStart > today, since issuedAt >= periodStart
     * always). PURE — no DB writes and no reads of existing rows (spec "Capped Catch-Up
     * Generation", "Generation Fires On or After Due Date", "Billed Records Are Immutable").
     *
     * A $cap of zero or less yields an empty chain, which is how a caller asks for "the
     * periods AFTER the one I already hold" when its budget is exhausted.
     *
     * @return PeriodRun[]
     */
    public function runsFor(
        ChargePolicy $policy,
        Carbon $seed,
        Carbon $today,
        int $cap,
        int $generationDay,
    ): array {
        $runs = [];
        $cursor = $seed->copy();

        while (count($runs) < $cap) {
            $run = $this->resolver->periodFor($policy->periodicity_id, $cursor, $generationDay);

            if ($run === null) {
                break;
            }

            if ($policy->valid_to !== null && Carbon::parse($policy->valid_to)->lessThan($run->issuedAt)) {
                break; // valid_to stops the chain.
            }

            if ($today->lessThan($run->issuedAt)) {
                break; // Not yet time — fires on or after issuedAt, not before.
            }

            $runs[] = $run;
            $cursor = $run->dueDate->copy()->addDay();
        }

        return $runs;
    }

    /**
     * Materialize (clone + activate) every run in $runs against the policy's template batch.
     *
     * NOTIFICATIONS: a single-run list keeps the existing per-batch push (activateBatch's
     * notifyResidents defaults to true); a multi-run list suppresses every per-batch push and
     * fires ONE consolidated "N cargos emitidos" notification per affected unit after all the
     * activations commit. A resident must never receive N pushes because the cluster fell N
     * periods behind (spec "Consolidated Catch-Up Notifications").
     *
     * @param  PeriodRun[]  $runs
     * @param  Closure|null  $log  Optional (string $message): void sink for progress lines —
     *                             the cron prints them, the HTTP path ignores them.
     * @return array<int, ChargeBatch>
     */
    public function materialize(
        ChargePolicy $policy,
        ChargeBatch $template,
        array $runs,
        ?Closure $log = null,
    ): array {
        if ($runs === []) {
            return [];
        }

        $log ??= static function (string $message): void {};

        $conceptName = $policy->concept?->name ?? $policy->name;
        $consolidated = count($runs) > 1;
        $generatedBatches = [];

        foreach ($runs as $run) {
            $title = "{$conceptName} · {$run->periodLabel}";

            try {
                $batch = $this->generationService->cloneInitialBatch($template, $run, $title);
                $this->generationService->activateBatch($batch, notifyResidents: ! $consolidated);
                $generatedBatches[] = $batch->refresh();

                $log("Batch created: {$title}");
            } catch (UniqueConstraintViolationException) {
                // A batch already exists for this policy/period_start. It may be a leftover
                // DRAFT from a previous run whose activate() threw after create() committed —
                // find it BY PERIOD_START (the idempotency key) and (re)activate.
                // activateBatch() is idempotent for already-active batches.
                $existing = ChargeBatch::where('charge_policy_id', $policy->id)
                    ->whereDate('period_start', $run->periodStart->toDateString())
                    ->whereNull('deleted_at')
                    ->first();

                if ($existing) {
                    $this->generationService->activateBatch($existing, notifyResidents: ! $consolidated);
                    $generatedBatches[] = $existing->refresh();
                    $log("Batch already existed; ensured active: {$title}.");
                } else {
                    $log("Batch already exists: {$title} — skipped.");
                }
            }
        }

        if ($consolidated && $generatedBatches !== []) {
            $this->generationService->notifyConsolidatedChargesIssued(
                $policy,
                array_map(static fn (ChargeBatch $b): string => $b->id, $generatedBatches),
            );
        }

        return $generatedBatches;
    }

    /**
     * Convenience for the cron: resolve the chain from $seed and materialize it in one call.
     *
     * @return array<int, ChargeBatch>
     */
    public function generate(
        ChargePolicy $policy,
        ChargeBatch $template,
        Carbon $seed,
        Carbon $today,
        int $cap,
        int $generationDay,
        ?Closure $log = null,
    ): array {
        return $this->materialize(
            $policy,
            $template,
            $this->runsFor($policy, $seed, $today, $cap, $generationDay),
            $log,
        );
    }
}
