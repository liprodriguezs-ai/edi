<?php

namespace Modules\Collections\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Support\CollectionSettings;
use Modules\Setting\Models\Setting;

/**
 * ChargePolicyService — owns the create/update flow of a charge_policy and the IMMEDIATE
 * generation of its first batch(es). The mode is intrinsic to the periodicity (EVENTUAL =
 * direct one-shot; any automatic periodicity = recurrent), so create branches on it:
 *
 *  - PERIODIC policies (periodicity.is_automatic = true): create a PURE TEMPLATE batch
 *    (charge_batch.is_initial = true, DRAFT, NO charges) holding the target + reference dates,
 *    and IMMEDIATELY materialize the FIRST period as a CLONE (is_initial = false, ACTIVATED,
 *    WITH charges) using the exact same primitives the daily cron uses — plus, when
 *    valid_from is RETROACTIVE, every further period already due up to today, capped by
 *    volume (see materializeInitialPeriods / creationChainCap). The cron then clones the
 *    template for every subsequent period, seeding from the last one generated here
 *    (idempotent via the period_start unique index).
 *  - DIRECT / EVENTUAL policies (is_automatic = false): create a SINGLE one-shot batch
 *    (is_initial = false, ACTIVATED, WITH charges, month = null) at the dates supplied with the
 *    create request. No template — eventual never clones.
 *
 * Generation runs inside the same transaction as the policy insert so a target/date error
 * never leaves an orphan policy behind.
 */
class ChargePolicyService
{
    public function __construct(
        private readonly ChargeGenerationService $generationService,
        private readonly PeriodicityScheduleResolver $scheduleResolver,
        private readonly PeriodChainService $periodChain,
        private readonly TargetResolver $targetResolver,
    ) {}

    /**
     * Create a policy and, when active, generate its first batch(es) immediately.
     *
     * PERIODIC → a DRAFT template (no charges) + an ACTIVATED clone per period already due
     * (with charges): the first one always, plus the backlog of a retroactive valid_from.
     * DIRECT/EVENTUAL → a single ACTIVATED one-shot batch (with charges, no template).
     *
     * @param  array<string, mixed>  $attributes  Validated policy attributes plus the initial
     *                                            batch fields (target_type/target_selector and,
     *                                            for direct, issued_at/due_date).
     */
    public function create(array $attributes): ChargePolicy
    {
        return DB::transaction(function () use ($attributes): ChargePolicy {
            $policy = ChargePolicy::create(array_intersect_key($attributes, array_flip([
                'charge_concept_id',
                'name',
                'amount_mode_id',
                'fixed_amount',
                'min_amount',
                'ideal_amount',
                'max_amount',
                'prorate_total_amount',
                'grace_days',
                'due_soon_days',
                'periodicity_id',
                'valid_from',
                'valid_to',
                'is_active',
            ])));

            // Persist the optional late-fee schedule in the same transaction.
            if (array_key_exists('surcharge_rules', $attributes)) {
                $this->syncSurchargeRules($policy, $attributes['surcharge_rules'] ?? []);
            }

            if ($policy->is_active) {
                if ($policy->isPeriodic()) {
                    $target = $this->buildTargetParams($attributes);
                    $firstRun = $this->resolveFirstRun($policy);

                    // The template is a pure config holder (DRAFT, no charges) in BOTH
                    // branches; only whether periods are materialized now differs.
                    $template = $this->generationService->createInitialTemplate($policy, [
                        'target_type' => $target['target_type'],
                        'target_selector' => $target['target_selector'] ?? null,
                        'title' => $target['title'] ?? null,
                        'year' => $firstRun->year,
                        'issued_at' => $firstRun->issuedAt->toDateString(),
                        'due_date' => $firstRun->dueDate->toDateString(),
                    ]);

                    // A FUTURE valid_from creates ONLY the template (no charges): the cron
                    // opens the first period when the validity window opens. Today or in the
                    // past → materialize the first period and any backlog behind it.
                    if (! $policy->valid_from?->isFuture()) {
                        $this->materializeInitialPeriods($policy, $template, $firstRun, $target);
                    }
                } else {
                    // Eventual/direct: a single one-shot activated batch (no template).
                    $this->generationService->generateEventualBatch(
                        $policy,
                        $this->buildEventualParams($attributes),
                    );
                }
            }

            return $policy->refresh();
        });
    }

    /**
     * The target/config slice shared by both modes — copied onto the template (periodic) or
     * onto the single eventual batch.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{target_type: string, target_selector: array<string,mixed>|null, title: string|null}
     */
    private function buildTargetParams(array $attributes): array
    {
        return [
            'target_type' => $attributes['target_type'] ?? 'all',
            'target_selector' => $attributes['target_selector'] ?? null,
            'title' => $attributes['batch_title'] ?? null,
        ];
    }

    /**
     * Resolve the FIRST period of a PERIODIC policy — ANCHORED BILLING PERIODS: the first
     * period always STARTS exactly on valid_from (spec "First Period Anchored on Policy
     * Validity"). There is no re-anchor to "today" anymore: a backdated valid_from creates
     * its first period directly here, and the daily cron catch-up chain fills in any
     * remaining periods between that first due_date and today on its own. A future
     * valid_from still only reaches this method to describe the template's reference dates
     * (the caller branches on isFuture() and skips charge generation).
     */
    private function resolveFirstRun(ChargePolicy $policy): PeriodRun
    {
        $anchor = $policy->valid_from
            ? Carbon::parse($policy->valid_from)
            : Carbon::today();

        $run = $this->scheduleResolver->periodFor($policy->periodicity_id, $anchor, $this->generationDay());

        if ($run === null) {
            throw new \LogicException('A periodic policy must resolve a first period.');
        }

        return $run;
    }

    /**
     * Materialize the periods a brand-new PERIODIC policy already owes, in ONE shot.
     *
     * A RETROACTIVE valid_from means the policy is born behind: creating only its first
     * period would leave the rest of the backlog to the daily cron, which walks EVERY
     * tenant and EVERY policy — so recovering one rule's backlog by hand used to generate
     * unrelated policies' periods as a side effect. This chain is strictly scoped to
     * $policy: same seed math, same stop conditions and the same consolidated notification
     * as the cron (PeriodChainService), applied to this policy alone.
     *
     * The FIRST period is materialized UNCONDITIONALLY (unchanged behavior): it is the
     * anchored period of valid_from and is created even when its generation date has not
     * arrived yet — e.g. a cluster whose charge_generation_day is 10 and a policy starting
     * today. The chain then supplies only the periods AFTER it, which is where the
     * "today >= issuedAt" and valid_to stops legitimately apply.
     *
     * @param  array{target_type: string, target_selector: array<string,mixed>|null, title: string|null}  $target
     */
    private function materializeInitialPeriods(
        ChargePolicy $policy,
        ChargeBatch $template,
        PeriodRun $firstRun,
        array $target,
    ): void {
        $cap = $this->creationChainCap($target);

        $runs = [
            $firstRun,
            ...$this->periodChain->runsFor(
                $policy,
                $firstRun->dueDate->copy()->addDay(),
                Carbon::today(),
                $cap - 1,
                $this->generationDay(),
            ),
        ];

        $this->periodChain->materialize($policy, $template, $runs);
    }

    /**
     * How many periods a single CREATE request may materialize.
     *
     * Creation runs inside an HTTP request (and inside Orion's transaction), unlike the
     * cron, which is a CLI process with no request timeout. The bound is therefore on
     * CHARGES, not periods: periods × units is what actually gets inserted, so a 6-unit dev
     * cluster and a 300-unit condominium must not be allowed the same depth. With the
     * defaults (12 periods, 600 charges): up to 50 units → the full 12 periods; 300 units
     * → 2 periods; 1000 units → 1. The floor of 1 keeps the pre-existing guarantee that a
     * new active policy ALWAYS gets its first period.
     *
     * Whatever the cap leaves behind is not lost: the daily cron seeds from
     * MAX(due_date) + 1 and drains the remainder contiguously (with its own, larger cap,
     * which a CLI process can afford). Queueing is deliberately NOT used — this deployment
     * has no queue worker running, so a queued backlog would simply never materialize.
     *
     * @param  array{target_type: string, target_selector: array<string,mixed>|null, title: string|null}  $target
     */
    private function creationChainCap(array $target): int
    {
        $periodCeiling = max(1, (int) config('collections.catchup_max_periods', 12));
        $chargeBudget = max(1, (int) config('collections.creation_max_charges', 600));

        $unitCount = $this->targetResolver
            ->resolve($target['target_type'], $target['target_selector'])
            ->count();

        return max(1, min($periodCeiling, intdiv($chargeBudget, max(1, $unitCount))));
    }

    /**
     * The cluster's configured charge-generation day (NACE), with the documented default.
     */
    private function generationDay(): int
    {
        return (int) (Setting::getValue(CollectionSettings::GENERATION_DAY)
            ?? CollectionSettings::DEFAULT_GENERATION_DAY);
    }

    /**
     * Build the concrete one-shot params for an EVENTUAL (direct) policy. The dates are
     * supplied with the create request (required by ChargePolicyCreateRequest); they fall back
     * to today only if a non-HTTP caller (e.g. a seeder) omits them. month/cycle stay null.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{target_type: string, target_selector: array<string,mixed>|null, title: string|null, year: int, issued_at: string, due_date: string}
     */
    private function buildEventualParams(array $attributes): array
    {
        $issuedAt = $attributes['issued_at'] ?? Carbon::today()->toDateString();
        $dueDate = $attributes['due_date'] ?? $issuedAt;

        return [
            ...$this->buildTargetParams($attributes),
            'year' => (int) substr((string) $issuedAt, 0, 4),
            'issued_at' => $issuedAt,
            'due_date' => $dueDate,
        ];
    }

    /**
     * Update a policy's editable config and (when supplied) its late-fee schedule, in
     * one transaction. The concept is immutable (request-enforced). The mode is intrinsic
     * to the periodicity — periodicity_id itself stays editable only while the policy has
     * not billed yet (ChargePolicyUpdateRequest gates it with prohibitedIf(hasBilled)).
     *
     * Scope edits (target_type/target_selector, plus issued_at/due_date for a direct/eventual
     * policy) are routed to whichever charge_batch already stores that scope — the periodic
     * template (is_initial = true) or the single direct one-shot batch (is_initial = false) —
     * but ONLY while the policy has not billed yet. This mirrors (defensively re-checks) the
     * gate already enforced by ChargePolicyUpdateRequest, so a caller that bypasses the
     * FormRequest can never silently rewrite the scope of an already-billed policy.
     *
     * @param  array<string, mixed>  $attributes  Validated update attributes (may include
     *                                            a `surcharge_rules` array to REPLACE the schedule,
     *                                            and/or target_type/target_selector/issued_at/due_date
     *                                            to relocate the policy's scope pre-billing).
     */
    public function update(ChargePolicy $policy, array $attributes): ChargePolicy
    {
        return DB::transaction(function () use ($policy, $attributes): ChargePolicy {
            // Computed ONCE, before the fill/save below, so it reflects whether the policy
            // had billed BEFORE this update (this update never creates charges itself).
            $hasBilled = $policy->charges()->exists();

            $policy->fill(Arr::only($attributes, [
                'name', 'amount_mode_id', 'fixed_amount', 'min_amount', 'ideal_amount',
                'max_amount', 'prorate_total_amount', 'grace_days', 'due_soon_days', 'periodicity_id',
                'valid_from', 'valid_to', 'is_active',
            ]));
            $policy->save();

            if (array_key_exists('surcharge_rules', $attributes)) {
                $this->syncSurchargeRules($policy, $attributes['surcharge_rules'] ?? []);
            }

            if (! $hasBilled && array_key_exists('target_type', $attributes)) {
                $this->updateScopeBatch($policy, $attributes);
            }

            return $policy->refresh();
        });
    }

    /**
     * Relocate the policy's scope (target_type/target_selector, and for a direct/eventual
     * policy also issued_at/due_date) onto the batch that already owns it. Called only when
     * !hasBilled (see update()).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateScopeBatch(ChargePolicy $policy, array $attributes): void
    {
        $batch = $this->resolveScopeBatch($policy);

        // No-op: e.g. a periodic policy paused-at-creation has no template yet (a known,
        // out-of-scope limitation tied to the is_active false→true regeneration gap).
        if (! $batch) {
            return;
        }

        $batch->target_type = $attributes['target_type'];
        $batch->target_selector = $attributes['target_selector'] ?? null;

        if ($policy->isDirect()) {
            if (array_key_exists('issued_at', $attributes)) {
                $batch->issued_at = $attributes['issued_at'];
            }

            if (array_key_exists('due_date', $attributes)) {
                $batch->due_date = $attributes['due_date'];
            }
        }

        $batch->save();
    }

    /**
     * Resolve the SINGLE charge_batch that stores a not-yet-billed policy's scope:
     *  - PERIODIC: the template (is_initial = true). The partial unique index
     *    charge_batch_initial_unique guarantees at most one exists.
     *  - DIRECT/EVENTUAL: its single one-shot batch (is_initial = false). More than one
     *    would violate the "one batch per eventual policy" invariant — abort loudly instead
     *    of guessing which one to update.
     *
     * Returns null when there is nothing to update yet (periodic policy paused-at-creation,
     * no template — see updateScopeBatch()).
     */
    private function resolveScopeBatch(ChargePolicy $policy): ?ChargeBatch
    {
        if ($policy->isPeriodic()) {
            return $policy->charge_batches()->where('is_initial', true)->first();
        }

        $directBatches = $policy->charge_batches()->where('is_initial', false)->get();

        if ($directBatches->count() > 1) {
            abort(422, 'No se puede determinar el lote a actualizar');
        }

        return $directBatches->first();
    }

    /**
     * Delete a policy and cascade the soft-delete to its batches and their charges.
     *
     * A policy is deletable only while it has NO LIVE BILLING. Live billing means at least one
     * `charge` of the policy that is not covered by a CANCELLED batch: a charge in a DRAFT/ACTIVE
     * batch, or a batch-less charge (no cancelled batch proves it dead, so it counts as live).
     *
     * Counting BATCHES instead would block every periodic policy forever: creation always leaves
     * a template batch behind (is_initial = true, no charges), so "has batches" is true from the
     * very first second. The template and any cancelled batch carry no live money, hence the
     * cascade: without it they would survive as orphans pointing at a deleted policy and keep
     * showing up in the general charge-batch list.
     */
    public function delete(ChargePolicy $policy): void
    {
        DB::transaction(function () use ($policy): void {
            $hasLiveBilling = $policy->charges()
                ->whereDoesntHave('batch', function ($query): void {
                    $query->where('charge_batch_status_id', ChargeBatchStatus::CANCELLED);
                })
                ->exists();

            if ($hasLiveBilling) {
                abort(422, 'Esta política ya generó cobros; desactívala en su lugar.');
            }

            $batchIds = $policy->charge_batches()->pluck('id')->all();

            if ($batchIds !== []) {
                // Mass soft-delete: model events do not fire, so the audit column the
                // GlobalObserver would stamp on `deleted` is written here explicitly.
                $auditAttributes = [
                    'deleted_at' => Carbon::now(),
                    'user_deleted_id' => auth()->id(),
                ];

                Charge::whereIn('charge_batch_id', $batchIds)->update($auditAttributes);
                ChargeBatch::whereIn('id', $batchIds)->update($auditAttributes);
            }

            $policy->delete();
        });
    }

    /**
     * Sync (create / update / delete) the late-fee rules of a policy to match the supplied
     * array. The array is the FULL desired set: rows with an `id` matching an existing rule
     * are updated, rows without (or with an unknown id) are created, and any existing rule
     * whose id is not in the array is soft-deleted. A null/empty array clears the schedule.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function syncSurchargeRules(ChargePolicy $policy, array $rules): void
    {
        $keptIds = [];

        foreach ($rules as $rule) {
            $payload = [
                'charge_policy_id' => $policy->id,
                'from_month' => (int) $rule['from_month'],
                'surcharge_mode_id' => $rule['surcharge_mode_id'],
                'surcharge_value' => (int) $rule['surcharge_value'],
                'recurrence' => $rule['recurrence'],
                'cap_amount' => isset($rule['cap_amount']) ? (int) $rule['cap_amount'] : null,
                'is_active' => array_key_exists('is_active', $rule) ? (bool) $rule['is_active'] : true,
            ];

            $existing = isset($rule['id'])
                ? $policy->surcharge_rules()->whereKey($rule['id'])->first()
                : null;

            if ($existing) {
                $existing->fill($payload);
                $existing->save();
                $keptIds[] = $existing->id;
            } else {
                $created = ChargeSurchargeRule::create($payload);
                $keptIds[] = $created->id;
            }
        }

        // Remove rules no longer present in the desired set.
        $policy->surcharge_rules()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }
}
