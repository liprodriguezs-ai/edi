<?php

namespace Modules\Collections\Console\Commands;

use App\Notifications\NotificationCategory;
use App\Notifications\Services\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\PeriodChainService;
use Modules\Collections\Support\CollectionSettings;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;

/**
 * GenerateMonthlyChargesCommand — runs DAILY at 06:00; self-filters per tenant.
 *
 * ANCHORED BILLING PERIODS: each policy's next period is CHAINED from its last batch
 * (seed = MAX(due_date) + 1 day, or valid_from for a virgin policy), never from a
 * calendar-absolute grid. This command owns only the SELECTION (which tenants/policies,
 * which seed, which cap) — the chain walk and its materialization live in
 * PeriodChainService, shared verbatim with ChargePolicyService::create so a policy
 * created with a retroactive valid_from produces exactly the periods this cron would
 * have produced for it. The chain generates every period whose issuedAt has arrived
 * (today >= issuedAt), up to a per-run cap, and STOPS when the policy's valid_to has
 * passed or the next period has not started yet. Because the seed always reads the LAST
 * already-generated batch, this is strictly prospective — no existing charge_batch/charge
 * row is ever recomputed, re-dated, or re-sliced.
 *
 * IDEMPOTENCY: a partial unique index on (tenant_id, charge_policy_id, period_start)
 * WHERE deleted_at IS NULL AND period_start IS NOT NULL prevents duplicate batches for the
 * same anchored period. The chain's seed is read from the MAX(due_date) of ACTIVE/CANCELLED
 * batches only (a DRAFT batch — created but never activated, e.g. a crashed prior run — is
 * NOT counted as "already generated"), so a genuinely stuck DRAFT is legitimately retried:
 * the INSERT hits the unique constraint, UniqueConstraintViolationException is caught, and
 * the existing row (looked up by period_start) is reactivated — idempotent either way.
 *
 * CATCH-UP CAP: at most config('collections.catchup_max_periods') (default 12) periods are
 * generated per policy per run; any remainder generates on the next run, preserving chain
 * order — never skipping ahead.
 *
 * CONSOLIDATED NOTIFICATIONS: when a run generates more than one period for a policy, every
 * activation in the chain suppresses its own push and ONE consolidated "N cobros emitidos"
 * notification fires per affected unit afterward (spec "Consolidated Catch-Up
 * Notifications"). A single-period run keeps the existing per-batch notify path unchanged.
 *
 * `--from=YYYY-MM-DD` is a DEEP CATCH-UP AUTHORIZATION, not a period selector — the chain
 * alone determines WHAT generates (it can never skip ahead or rewind). Supplying --from:
 *  (a) keeps the same validation as before — well-formed YYYY-MM-DD, not future, and no more
 *      than MAX_CATCHUP_DAYS in the past (anti-typo guard);
 *  (b) lifts the per-run cap for this run to a defensive ceiling of MAX_CATCHUP_DAYS/2 = 200
 *      periods/policy (the densest cadence over the max declared horizon), warning if hit;
 *  (c) if a policy's actual chain seed is EARLIER than the declared date, its earlier
 *      backlog still generates (chain order is inviolable) — the operator gets a warning
 *      that the backlog predates what they declared;
 *  (d) a date at or before the last generated period is INERT: generation only ever appends
 *      after MAX(due_date), so --from can never regenerate, re-date, or reslice a batch that
 *      already exists.
 */
class GenerateMonthlyChargesCommand extends Command
{
    protected $signature = 'collections:generate-monthly
        {--from= : Fecha (YYYY-MM-DD) que autoriza una recuperación profunda de períodos atrasados}';

    protected $description = 'Generate recurring charge batches for all automatic periodicities (daily cron).';

    /**
     * Defensive cap on the --from catch-up authorization: a typo like --from=1970-01-01
     * would otherwise declare an unbounded backlog depth.
     */
    private const MAX_CATCHUP_DAYS = 400;

    public function handle(PeriodChainService $periodChain): int
    {
        $today = Carbon::today();

        try {
            $catchUpFrom = $this->resolveCatchUpFrom($today);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Without --from: the configured per-run cap. With --from: lifted to the defensive
        // ceiling (decision 10b) — the declared date only authorizes depth, it does not
        // itself select which periods generate.
        $cap = $catchUpFrom !== null
            ? intdiv(self::MAX_CATCHUP_DAYS, 2)
            : (int) config('collections.catchup_max_periods', 12);

        $tenants = Tenant::all();

        // Hoist: central catalog — same rows for every tenant; one DB query total.
        $autoPeriodicities = Periodicity::where('is_automatic', true)
            ->where('is_active', true)
            ->get();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($periodChain, $today, $catchUpFrom, $cap, $tenant, $autoPeriodicities): void {
                $generationDay = (int) (Setting::getValue(CollectionSettings::GENERATION_DAY) ?? CollectionSettings::DEFAULT_GENERATION_DAY);

                $this->generateForTenant($periodChain, $today, $catchUpFrom, $cap, $tenant, $generationDay, $autoPeriodicities);
            });
        }

        $this->info('collections:generate-monthly completed.');

        return self::SUCCESS;
    }

    /**
     * Validate --from and return the declared catch-up date, or null when the flag was not
     * supplied (normal daily-cron mode, single period per policy).
     *
     * @throws \InvalidArgumentException When --from is malformed, in the future, or too far back.
     */
    private function resolveCatchUpFrom(Carbon $today): ?Carbon
    {
        $from = $this->option('from');

        if ($from === null || $from === '') {
            return null;
        }

        $from = (string) $from;

        // '!' resets the time to 00:00. The round-trip comparison is what rejects an
        // out-of-range day: createFromFormat happily rolls 2026-02-30 over to 2026-03-02.
        try {
            $start = Carbon::createFromFormat('!Y-m-d', $from) ?: null;
        } catch (\Throwable) {
            $start = null;
        }

        if ($start === null || $start->format('Y-m-d') !== $from) {
            throw new \InvalidArgumentException("La opción --from debe ser una fecha válida con formato YYYY-MM-DD (se recibió: {$from}).");
        }

        if ($start->greaterThan($today)) {
            throw new \InvalidArgumentException('La opción --from no puede ser una fecha futura.');
        }

        $days = (int) $start->diffInDays($today) + 1;

        if ($days > self::MAX_CATCHUP_DAYS) {
            throw new \InvalidArgumentException(
                'El rango de --from no puede exceder '.self::MAX_CATCHUP_DAYS." días (se solicitaron {$days}).",
            );
        }

        return $start;
    }

    private function generateForTenant(
        PeriodChainService $periodChain,
        Carbon $today,
        ?Carbon $catchUpFrom,
        int $cap,
        Tenant $tenant,
        int $generationDay,
        Collection $autoPeriodicities,
    ): void {
        $tenantLabel = $tenant->name ?? $tenant->id;

        // Guard: the batch-status catalog must be seeded for this tenant context before we
        // try to clone/activate anything (cloneInitialBatch resolves DRAFT via findOrFail).
        if (! ChargeBatchStatus::find(ChargeBatchStatus::DRAFT)) {
            return;
        }

        $periodicityIds = $autoPeriodicities->pluck('id')->all();

        if ($periodicityIds === []) {
            return;
        }

        // ONE policies query for every automatic periodicity — active, with an active
        // concept. Only PERIODIC policies are cron-driven (the mode is intrinsic to the
        // periodicity: every match here is automatic=true by construction). Deliberately NOT
        // filtered by valid_from here: per decision 7, valid_from is inert once a policy has
        // chained (its job — anchoring the FIRST period — is already done), so an admin
        // editing it to any date post-billing must never gate FUTURE generation. The
        // future-valid_from skip for a still-virgin policy is applied per-policy below,
        // where lastDueDate is known.
        $policies = ChargePolicy::query()
            ->whereIn('periodicity_id', $periodicityIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('concept', fn ($q) => $q->where('is_active', true))
            ->with('concept')
            ->get();

        if ($policies->isEmpty()) {
            return;
        }

        $policyIds = $policies->pluck('id')->all();

        // ONE aggregate: the last due_date per policy, over non-template, non-deleted,
        // SETTLED batches (ACTIVE or CANCELLED) — INCLUDES cancelled, so a cancelled period
        // is skipped (not regenerated), never recomputed. EXCLUDES DRAFT: a batch stuck in
        // DRAFT (created but never activated, e.g. a crashed prior run) must NOT count as
        // "already generated" — the chain legitimately retries that same period_start, hits
        // the unique constraint, and the catch below recovers/reactivates the leftover row.
        $lastDueDates = ChargeBatch::whereIn('charge_policy_id', $policyIds)
            ->where('is_initial', false)
            ->whereIn('charge_batch_status_id', [ChargeBatchStatus::ACTIVE, ChargeBatchStatus::CANCELLED])
            ->whereNull('deleted_at')
            ->selectRaw('charge_policy_id, MAX(due_date) AS last_due_date')
            ->groupBy('charge_policy_id')
            ->pluck('last_due_date', 'charge_policy_id');

        // ONE templates query — the row each period clones.
        $templatesByPolicy = ChargeBatch::whereIn('charge_policy_id', $policyIds)
            ->where('is_initial', true)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('charge_policy_id');

        $allGeneratedBatches = [];

        foreach ($policies as $policy) {
            $template = $templatesByPolicy->get($policy->id);

            if (! $template) {
                // An active periodic policy should always have a template batch (the
                // initial batch is created with the policy). If missing, skip loudly.
                $this->warn("[{$tenantLabel}] Policy {$policy->id} has no initial batch — skipped.");

                continue;
            }

            $lastDueDate = $lastDueDates->get($policy->id);

            // A virgin policy (no chain yet) is gated by valid_from — a future valid_from
            // is not vigent yet, skip until it opens. Once chained, valid_from is inert
            // (see the query comment above): the seed comes from the last batch, period.
            if (! $lastDueDate && Carbon::parse($policy->valid_from)->startOfDay()->greaterThan($today)) {
                continue;
            }

            $seed = $lastDueDate
                ? Carbon::parse($lastDueDate)->addDay()
                : Carbon::parse($policy->valid_from)->startOfDay();

            if ($catchUpFrom !== null && $seed->lessThan($catchUpFrom)) {
                // Chain order is inviolable — the seed still governs what generates. This is
                // purely informational: the operator's declared depth undershot reality.
                $this->warn("[{$tenantLabel}] Policy {$policy->id} backlog predates --from={$catchUpFrom->toDateString()} (seed {$seed->toDateString()}); generating anyway.");
            }

            $batchesForPolicy = $periodChain->generate(
                $policy,
                $template,
                $seed,
                $today,
                $cap,
                $generationDay,
                log: fn (string $message) => $this->info("[{$tenantLabel}] {$message}"),
            );

            if ($catchUpFrom !== null && count($batchesForPolicy) === $cap) {
                $this->warn("[{$tenantLabel}] Policy {$policy->id} hit the --from catch-up ceiling ({$cap} periods) — backlog may not be fully drained yet.");
            }

            $allGeneratedBatches = [...$allGeneratedBatches, ...$batchesForPolicy];
        }

        // ONE summary per tenant covering the whole run (a deep catch-up would otherwise
        // spam the treasurer with one notification per period).
        $this->notifyTreasurerSummary($tenant, $allGeneratedBatches);
    }

    /**
     * Flujo A.8 (§8.8): after generation, the treasurer receives an email summary.
     * Tenant-scoped role fan-out via the dispatcher (cron context — never a global role query).
     *
     * @param  array<int, ChargeBatch>  $generatedBatches
     */
    private function notifyTreasurerSummary(Tenant $tenant, array $generatedBatches): void
    {
        if ($generatedBatches === []) {
            return;
        }

        $lines = [];
        foreach ($generatedBatches as $batch) {
            $chargeCount = Charge::where('charge_batch_id', $batch->id)
                ->where('is_cancelled', false)
                ->count();
            $lines[] = "{$batch->title}: {$chargeCount} cargo(s)";
        }

        try {
            app(NotificationDispatcher::class)->toTenantRole(
                $tenant,
                ['treasurer'],
                'Cargos generados',
                'Se generaron los cobros. '.implode(' · ', $lines),
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/charge-batch',
                ],
                ['log', 'resend_email'],
            );
        } catch (\Throwable $e) {
            // A notification failure must never break charge generation.
            report($e);
        }
    }
}
