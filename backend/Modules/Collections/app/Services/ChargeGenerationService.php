<?php

namespace Modules\Collections\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Housing\Models\Unit;
use Modules\Notification\Services\NotificationSettingService;

/**
 * ChargeGenerationService — activates a ChargeBatch (draft→active transition).
 *
 * Responsibilities:
 *  - Verify the batch is in draft status (re-checked UNDER LOCK inside the transaction).
 *  - Resolve target units via TargetResolver.
 *  - Compute expected_amount per unit via AmountCalculator.
 *  - Create one Charge per unit (idempotent: app-level skip + DB unique backstop).
 *  - Auto-apply each unit's credit balance to the new charges (Flujo C.5, §8.7.3.4).
 *  - Assign initial charge status via ChargeStatusService.
 *  - Update batch status to active and recompute progress_pct.
 *
 * All mutations happen inside a single DB transaction.
 */
class ChargeGenerationService
{
    use NotifiesResidents;

    public function __construct(
        private readonly TargetResolver $targetResolver,
        private readonly AmountCalculator $amountCalculator,
        private readonly ChargeStatusService $chargeStatusService,
    ) {}

    /**
     * Activate a batch: generate charges and set status to active.
     *
     * @param  ChargeBatch  $batch  Must be in draft status.
     * @param  bool  $notifyResidents  When false, suppresses the per-batch "charge created"
     *                                 push (§8.9). Used by the catch-up chain, which fires ONE
     *                                 consolidated notification per unit after the whole run
     *                                 instead (see notifyConsolidatedChargesIssued). Defaults to
     *                                 true so every existing single-batch caller is unaffected.
     * @return ChargeBatch The refreshed batch with charges loaded.
     *
     * @throws \InvalidArgumentException If batch is already cancelled.
     */
    public function activateBatch(ChargeBatch $batch, bool $notifyResidents = true): ChargeBatch
    {
        // Guard: a template (is_initial=true) is NEVER activated directly — the cron clones
        // it per period and activates the clone. Activating the template would create charges
        // on the wrong row and prevent the cron from idempotently re-cloning it.
        if ($batch->is_initial) {
            throw new \InvalidArgumentException('La plantilla inicial no se activa ni genera cargos; el cron la clona por período.');
        }

        // Cheap pre-checks on the in-memory model; the AUTHORITATIVE re-check happens
        // below on the row locked inside the transaction (findings collections-09 /
        // sec-money-04 — two concurrent activations must not double-charge units).
        if ($batch->charge_batch_status_id === ChargeBatchStatus::ACTIVE) {
            // Idempotent: already active, return as-is.
            return $batch->load('charges');
        }

        if ($batch->charge_batch_status_id === ChargeBatchStatus::CANCELLED) {
            throw new \InvalidArgumentException('No se puede activar un cobro cancelado.');
        }

        $chargedUnitIds = [];

        $result = DB::transaction(function () use ($batch, &$chargedUnitIds): ChargeBatch {
            // Serialize concurrent activations: a racing request waits on this lock,
            // then sees ACTIVE on the fresh row and returns idempotently.
            $batch = ChargeBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();

            if ($batch->charge_batch_status_id === ChargeBatchStatus::ACTIVE) {
                return $batch->load('charges', 'status');
            }

            if ($batch->charge_batch_status_id === ChargeBatchStatus::CANCELLED) {
                throw new \InvalidArgumentException('No se puede activar un cobro cancelado.');
            }

            $activeStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::ACTIVE);
            $pendingStatusId = $this->chargeStatusService->resolveStatusId(ChargeStatus::PENDING);

            $units = $this->targetResolver->resolve(
                $batch->target_type,
                $batch->target_selector,
            );

            // Idempotency: find unit IDs already charged in this batch.
            $alreadyChargedUnitIds = Charge::where('charge_batch_id', $batch->id)
                ->pluck('unit_id')
                ->flip()
                ->all();

            $policy = $batch->policy;

            // For by_share policies, precompute a zero-drift allocation across the WHOLE
            // unit set via largest-remainder so Σ(charges) == prorate_total.
            $shareAllocation = null;
            if ($policy?->amount_mode_id === AmountMode::BY_SHARE) {
                $shareAllocation = $this->amountCalculator->computeByShareAllocation($policy, $units);
            }

            foreach ($units as $unit) {
                if (isset($alreadyChargedUnitIds[$unit->id])) {
                    continue; // Skip already-generated charge.
                }

                $expectedRaw = $shareAllocation !== null
                    ? ($shareAllocation[$unit->id] ?? 0)
                    : $this->amountCalculator->compute($policy, $unit);

                $charge = $this->createChargeIfAbsent([
                    'charge_batch_id' => $batch->id,
                    'charge_concept_id' => $policy?->charge_concept_id,
                    'charge_policy_id' => $batch->charge_policy_id,
                    'unit_id' => $unit->id,
                    'expected_amount' => $expectedRaw,
                    'paid_amount' => 0,
                    'issued_at' => $batch->issued_at,
                    'due_date' => $batch->due_date,
                    'grace_days' => $policy?->grace_days,
                    'due_soon_days' => $policy?->due_soon_days,
                    'charge_status_id' => $pendingStatusId, // Will be recomputed below.
                    'is_cancelled' => false,
                ]);

                if (! $charge) {
                    continue; // DB backstop: a concurrent run already charged this unit.
                }

                // Recompute status immediately (may be due_soon or overdue for backdated batches).
                $this->chargeStatusService->recomputeCharge($charge);

                $chargedUnitIds[] = $unit->id;
            }

            // Set batch to active.
            $batch->charge_batch_status_id = $activeStatus->id;
            $batch->save();

            // Flujo C.5 (§8.7.3.4): consume each newly-charged unit's available credit
            // balance automatically, INSIDE the same generation transaction.
            $this->autoApplyCreditFor($chargedUnitIds);

            // Recompute progress.
            $this->recomputeBatchProgress($batch);

            // Recompute unit aggregates for all affected units (single batch query,
            // not one round trip per unit).
            $this->chargeStatusService->recomputeUnitAggregates($units->pluck('id')->all());

            return $batch->load('charges', 'status');
        });

        // §8.9: a SINGLE batch-activation push per affected unit (one notification per
        // unit's titular, NOT one per individual charge — avoids spamming residents).
        // Dispatched AFTER commit so a notification error cannot roll back generation.
        if ($notifyResidents) {
            $this->notifyBatchActivated($batch, $chargedUnitIds);
        }

        return $result;
    }

    /**
     * Create a PERIODIC policy's INITIAL (template) batch — a PURE CONFIG holder with NO charges.
     *
     * The template is born DRAFT and is NEVER activated: it only records the target
     * (target_type + target_selector) and a reference issued_at/due_date/year. The daily cron
     * CLONES it (cloneInitialBatch) into a real, activated batch for every period — so the
     * charges always live in the activated clones, never in the template.
     *
     * month/cycle are NULL on purpose so the template never occupies a slot in
     * charge_batch_period_unique (WHERE month IS NOT NULL) — the period clones own those slots.
     * Exactly one initial batch may exist per policy (charge_batch_initial_unique partial index).
     *
     * @param  array{target_type: string, target_selector?: array<string,mixed>|null, title?: string|null, year?: int|null, issued_at: string, due_date: string}  $params
     */
    public function createInitialTemplate(ChargePolicy $policy, array $params): ChargeBatch
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
        $title = $params['title']
            ?? trim(($policy->concept?->name ?? 'Cobro').' · '.$policy->name);

        return ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => $title,
            'year' => $params['year'] ?? (int) now()->year,
            // Period-agnostic: the template never represents a billable period itself.
            'month' => null,
            'cycle' => null,
            'target_type' => $params['target_type'],
            'target_selector' => $params['target_selector'] ?? null,
            'issued_at' => $params['issued_at'],
            'due_date' => $params['due_date'],
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
            'is_initial' => true,
        ]);
    }

    /**
     * EVENTUAL (direct / one-shot) policy creation: a SINGLE activated batch with concrete
     * dates and NO template (is_initial = false). Eventual policies never recur, so the cron
     * never touches them and there is nothing to clone. month/cycle are NULL.
     *
     * The batch is born DRAFT and activated in-line so its charges exist right away and it
     * shows up in COBROS GENERADOS (which filters is_initial = false).
     *
     * @param  array{target_type: string, target_selector?: array<string,mixed>|null, title?: string|null, year?: int|null, issued_at: string, due_date: string}  $params
     */
    public function generateEventualBatch(ChargePolicy $policy, array $params): ChargeBatch
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
        $title = $params['title']
            ?? trim(($policy->concept?->name ?? 'Cobro').' · '.$policy->name);

        $batch = ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => $title,
            'year' => $params['year'] ?? (int) now()->year,
            'month' => null,
            'cycle' => null,
            'target_type' => $params['target_type'],
            'target_selector' => $params['target_selector'] ?? null,
            'issued_at' => $params['issued_at'],
            'due_date' => $params['due_date'],
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
            'is_initial' => false,
        ]);

        return $this->activateBatch($batch);
    }

    /**
     * Clone the policy's INITIAL/template batch into a new batch for the given period.
     *
     * The cron calls this once per firing period: the target (target_type + target_selector)
     * and presentation come from the template, while year/month/cycle/issued_at/due_date come
     * from the PeriodRun. The copy is NOT the template (is_initial = false) and is born DRAFT;
     * the caller activates it. Idempotency for the period is the caller's concern (the
     * charge_batch_period_unique index + UniqueConstraintViolation handling).
     */
    public function cloneInitialBatch(ChargeBatch $initial, PeriodRun $run, string $title): ChargeBatch
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

        return ChargeBatch::create([
            'charge_policy_id' => $initial->charge_policy_id,
            'title' => $title,
            'year' => $run->year,
            'month' => $run->month,
            'cycle' => $run->cycle,
            // Anchored billing periods: the idempotency key. NULL only for PeriodRuns still
            // built by the legacy calendar-grid resolver (deleted in the same PR's cutover).
            'period_start' => $run->periodStart?->toDateString(),
            // Copy the template's target so each period bills the SAME audience the admin
            // configured at creation — never a blanket 'all'.
            'target_type' => $initial->target_type,
            'target_selector' => $initial->target_selector,
            'issued_at' => $run->issuedAt->toDateString(),
            'due_date' => $run->dueDate->toDateString(),
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
            'is_initial' => false,
        ]);
    }

    /**
     * Cancel an active/draft batch and its still-unpaid charges (spec §8.4 estados +
     * §8.7.2.5 — finding collections-13). Transactional and serialized via the batch
     * row lock. A batch with ANY payment application on a live charge is NOT cancellable
     * as a whole — payments must be cancelled first (mirrors the per-charge rule).
     *
     * @throws \InvalidArgumentException If already cancelled or any charge has payments.
     */
    public function cancelBatch(ChargeBatch $batch, string $reason): ChargeBatch
    {
        return DB::transaction(function () use ($batch, $reason): ChargeBatch {
            $batch = ChargeBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();

            if ($batch->charge_batch_status_id === ChargeBatchStatus::CANCELLED) {
                throw new \InvalidArgumentException('El cobro ya está cancelado.');
            }

            $hasPayments = Charge::where('charge_batch_id', $batch->id)
                ->where('is_cancelled', false)
                ->whereHas('payment_applications')
                ->exists();

            if ($hasPayments) {
                throw new \InvalidArgumentException(
                    'No se puede cancelar un cobro con pagos aplicados. Cancela los pagos primero.'
                );
            }

            $charges = Charge::where('charge_batch_id', $batch->id)
                ->where('is_cancelled', false)
                ->lockForUpdate()
                ->get();

            $affectedUnitIds = [];
            foreach ($charges as $charge) {
                $charge->is_cancelled = true;
                $charge->cancellation_reason = $reason;
                $charge->save();
                $affectedUnitIds[$charge->unit_id] = true;
            }

            $batch->charge_batch_status_id = ChargeBatchStatus::CANCELLED;
            $batch->save();

            $this->recomputeBatchProgress($batch);

            $this->chargeStatusService->recomputeUnitAggregates(array_keys($affectedUnitIds));

            return $batch->refresh()->load('charges', 'status');
        });
    }

    /**
     * Auto-charge a NEWLY created unit (spec §6.5 r4).
     *
     * "Cuando se da de alta una unidad, automáticamente se le aplican los cobros activos
     *  que coinciden con su tipo (si las hay)."
     *
     * INTERPRETATION (chosen): generate a charge for the unit for every ACTIVE charge_batch
     * of the CURRENT period whose target NOW includes this unit. The target membership is
     * re-resolved through the SAME TargetResolver used at activation, so target_type semantics
     * (all / by_type / by_tower / …) are honored — a mid-period new unit is no longer skipped.
     *
     * "Current period" = batches with no month (eventual) OR matching the current year+month
     * (so we never resurrect a closed prior month). Amounts come from AmountCalculator (per-unit;
     * by_share uses its own rounding — exact zero-drift allocation only applies to whole-batch
     * activation). Idempotent: an existing charge for the same (batch, unit) is skipped at the
     * app level AND enforced by the charge_batch_unit_unique index, so re-running or racing
     * never double-charges. Inactive units are excluded by TargetResolver (§6.5 r3), so this
     * never fires for them.
     *
     * SAFETY: runs in its own transaction; a failure here must NOT abort unit creation, so the
     * caller (observer) guards the call with try/catch + report().
     *
     * @return array<int, string> Charge ids created (for status recompute / tests).
     */
    public function generateForNewUnit(Unit $unit): array
    {
        // Units whose status is non-billable never receive new charges (§6.5 r3)
        // — mirror TargetResolver.
        if ($unit->unitStatus && ! $unit->unitStatus->is_billable) {
            return [];
        }

        $now = now();

        $activeBatches = ChargeBatch::query()
            ->where('charge_batch_status_id', ChargeBatchStatus::ACTIVE)
            ->where(function ($q) use ($now): void {
                // Eventual batches (no month) OR the current year+month period.
                $q->whereNull('month')
                    ->orWhere(function ($q2) use ($now): void {
                        $q2->where('year', $now->year)->where('month', $now->month);
                    });
            })
            ->get();

        if ($activeBatches->isEmpty()) {
            return [];
        }

        $createdChargeIds = [];

        DB::transaction(function () use ($activeBatches, $unit, &$createdChargeIds): void {
            $pendingStatusId = $this->chargeStatusService->resolveStatusId(ChargeStatus::PENDING);

            foreach ($activeBatches as $batch) {
                // Does this batch's target NOW include the new unit?
                $targeted = $this->targetResolver->resolve($batch->target_type, $batch->target_selector);
                if (! $targeted->contains('id', $unit->id)) {
                    continue;
                }

                // Idempotency: skip if this unit already has a charge for this batch.
                $exists = Charge::where('charge_batch_id', $batch->id)
                    ->where('unit_id', $unit->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $policy = $batch->policy;
                $expectedRaw = $this->amountCalculator->compute($policy, $unit);

                $charge = $this->createChargeIfAbsent([
                    'charge_batch_id' => $batch->id,
                    'charge_concept_id' => $policy?->charge_concept_id,
                    'charge_policy_id' => $batch->charge_policy_id,
                    'unit_id' => $unit->id,
                    'expected_amount' => $expectedRaw,
                    'paid_amount' => 0,
                    'issued_at' => $batch->issued_at,
                    'due_date' => $batch->due_date,
                    'grace_days' => $policy?->grace_days,
                    'due_soon_days' => $policy?->due_soon_days,
                    'charge_status_id' => $pendingStatusId,
                    'is_cancelled' => false,
                ]);

                if (! $charge) {
                    continue; // DB backstop: a concurrent run already charged this unit.
                }

                $this->chargeStatusService->recomputeCharge($charge);
                $this->recomputeBatchProgress($batch);

                $createdChargeIds[] = $charge->id;
            }

            if ($createdChargeIds !== []) {
                // Flujo C.5 (§8.7.3.4): the new charges consume any available credit.
                $this->autoApplyCreditFor([$unit->id]);
                $this->chargeStatusService->recomputeUnitAggregate($unit->id);
            }
        });

        return $createdChargeIds;
    }

    /**
     * Create a Charge inside a nested transaction (Postgres SAVEPOINT) so the
     * charge_batch_unit_unique violation from a concurrent generation aborts only
     * the savepoint — not the whole activation — and is treated as "already charged".
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createChargeIfAbsent(array $attributes): ?Charge
    {
        try {
            return DB::transaction(static fn (): Charge => Charge::create($attributes));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Auto-apply the credit balance of each unit that just received charges
     * (Flujo C.5, §8.7.3.4 — finding collections-04). Only units that actually have
     * a positive credit row are touched; PaymentService does the locked FIFO apply.
     * Resolved lazily to avoid a constructor cycle (PaymentService depends on this
     * service for batch progress recomputes).
     *
     * @param  array<int, string>  $unitIds
     */
    private function autoApplyCreditFor(array $unitIds): void
    {
        if ($unitIds === []) {
            return;
        }

        $unitIdsWithCredit = CreditBalance::query()
            ->whereIn('unit_id', array_values(array_unique($unitIds)))
            ->where('amount', '>', 0)
            ->pluck('unit_id');

        if ($unitIdsWithCredit->isEmpty()) {
            return;
        }

        $paymentService = app(PaymentService::class);

        foreach ($unitIdsWithCredit as $unitId) {
            $paymentService->applyUnitCreditFifo($unitId);
        }
    }

    /**
     * Notify the titular de pago of every newly-charged unit that a charge batch is active.
     *
     * @param  array<int, string>  $chargedUnitIds
     */
    private function notifyBatchActivated(ChargeBatch $batch, array $chargedUnitIds): void
    {
        // Resolve the per-cluster notification config ONCE (not per unit): gate on
        // is_active, then reuse the resolved settings for every recipient by passing
        // them to notifyEvent() as $preResolved (avoids an N-times resolve() in the loop).
        $resolved = app(NotificationSettingService::class)->resolve('charge_created');
        if (! $resolved['is_active']) {
            return;
        }

        $conceptName = $batch->title;
        $dueDate = $batch->due_date?->format('d/m/Y');

        foreach (array_unique($chargedUnitIds) as $unitId) {
            $titularId = $this->unitBillingUserId($unitId);
            if (! $titularId) {
                continue;
            }

            $this->notifyEvent(
                'charge_created',
                [$titularId],
                [
                    'concept_name' => $conceptName,
                    'due_date' => $dueDate,
                ],
                'Nuevo cobro emitido',
                'Se emitió un nuevo cobro: '.$conceptName.'. Revisa el detalle y la fecha de vencimiento.',
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/statement',
                    'charge_batch_id' => $batch->id,
                    'unit_id' => $unitId,
                ],
                $resolved,
            );
        }
    }

    /**
     * Consolidated catch-up notification (spec "Consolidated Catch-Up Notifications"): when
     * a single cron run generates MORE THAN ONE period for a policy, send AT MOST ONE
     * payment-holder notification per affected unit covering the WHOLE run — never one
     * notification per period. The caller activates every batch in the chain with
     * notifyResidents: false and calls this method once afterward.
     *
     * Renders the PLURAL copy variant `notification_template.charge_created_batch` (not
     * the singular `charge_created` one) via notifyEvent's $templateKey: the singular
     * template declares a single {due_date} that a multi-period run cannot fill, so
     * reusing it both leaked a literal "{due_date}" to residents and described N periods
     * as "un nuevo cargo". Configuration still comes from the charge_created EVENT.
     *
     * @param  array<int, string>  $batchIds  All batches generated for $policy in this run.
     */
    public function notifyConsolidatedChargesIssued(ChargePolicy $policy, array $batchIds): void
    {
        if ($batchIds === []) {
            return;
        }

        // Resolved ONCE (not per unit), mirroring notifyBatchActivated.
        $resolved = app(NotificationSettingService::class)->resolve('charge_created');
        if (! $resolved['is_active']) {
            return;
        }

        // Per unit: how many charges the run produced and the span their due dates
        // cover — the plural template's {first_due_date}/{last_due_date} replace the
        // singular {due_date}, which has no meaning across several periods.
        $summaryByUnit = Charge::whereIn('charge_batch_id', $batchIds)
            ->where('is_cancelled', false)
            ->selectRaw('unit_id, COUNT(*) AS charge_count, MIN(due_date) AS first_due_date, MAX(due_date) AS last_due_date')
            ->groupBy('unit_id')
            ->get();

        $conceptName = $policy->concept?->name ?? $policy->name;

        foreach ($summaryByUnit as $summary) {
            $titularId = $this->unitBillingUserId((string) $summary->unit_id);
            if (! $titularId) {
                continue;
            }

            $count = (int) $summary->charge_count;
            $context = [
                'concept_name' => $conceptName,
                'charge_count' => $count,
                'first_due_date' => $this->formatDueDate($summary->first_due_date),
                'last_due_date' => $this->formatDueDate($summary->last_due_date),
            ];

            $this->notifyEvent(
                'charge_created',
                [$titularId],
                $context,
                'Nuevos cargos: {concept_name}',
                'Se generaron {charge_count} cargos de {concept_name} correspondientes a varios periodos. Vencen entre el {first_due_date} y el {last_due_date}. Revisa el detalle en tu estado de cuenta.',
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/statement',
                    'charge_policy_id' => $policy->id,
                    'charge_count' => $count,
                ],
                $resolved,
                templateKey: 'charge_created_batch',
            );
        }
    }

    /**
     * Format an aggregate due_date for template substitution. The value comes back from
     * MIN()/MAX() as a raw driver string (no Eloquent cast on a selectRaw alias), so it
     * is parsed here rather than assumed to be a Carbon instance.
     */
    private function formatDueDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse((string) $value)->format('d/m/Y');
    }

    /**
     * Recompute and persist batch progress_pct.
     * progress_pct = (fully collected charges / total charges) * 100.
     *
     * An OVERPAID charge is fully collected (spec §8.4.1 — finding collections-15),
     * so it counts toward the numerator alongside COMPLETED.
     */
    public function recomputeBatchProgress(ChargeBatch $batch): void
    {
        $total = Charge::where('charge_batch_id', $batch->id)
            ->where('is_cancelled', false)
            ->count();

        if ($total === 0) {
            $batch->progress_pct = 0;
            $batch->save();

            return;
        }

        $completed = Charge::where('charge_batch_id', $batch->id)
            ->whereIn('charge_status_id', [ChargeStatus::COMPLETED, ChargeStatus::OVERPAID])
            ->where('is_cancelled', false)
            ->count();

        $batch->progress_pct = (int) round(($completed / $total) * 100);
        $batch->save();
    }
}
