<?php

namespace Modules\Projects\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\AmountCalculator;
use Modules\Collections\Services\ChargeService;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\Unit;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Models\ProjectContributionStatus;

/**
 * ProjectActivationService — transitions a community_project from draft → active.
 *
 * Mandatory participation: resolve target units via Collections TargetResolver,
 *   create ONE Charge per unit (with community_project_id set) AND one
 *   project_contribution per unit (expected_amount from amount mode).
 *
 * Voluntary participation: create one project_contribution per unit, NO charges.
 *
 * Idempotent: already-active projects are returned as-is.
 * Cancelled projects throw InvalidArgumentException.
 */
class ProjectActivationService
{
    use NotifiesResidents;

    public function __construct(
        private readonly TargetResolver $targetResolver,
        private readonly ChargeStatusService $chargeStatusService,
        private readonly AmountCalculator $amountCalculator,
        private readonly ChargeService $chargeService,
    ) {}

    /**
     * Activate a community project (draft → active).
     *
     * @throws \InvalidArgumentException If project is cancelled.
     */
    public function activate(CommunityProject $project): CommunityProject
    {
        $statusId = $project->community_project_status_id;

        if ($statusId === CommunityProjectStatus::ACTIVE) {
            // Idempotent: already active.
            return $project;
        }

        if ($statusId === CommunityProjectStatus::CANCELLED) {
            throw new \InvalidArgumentException('No se puede activar un proyecto cancelado.');
        }

        if ($statusId === CommunityProjectStatus::EXECUTED) {
            throw new \InvalidArgumentException('No se puede activar un proyecto ya ejecutado.');
        }

        $targetUnitIds = [];

        $result = DB::transaction(function () use ($project, &$targetUnitIds): CommunityProject {
            $activeStatus = CommunityProjectStatus::findOrFail(CommunityProjectStatus::ACTIVE);
            $pendingContributionStatus = ProjectContributionStatus::findOrFail(ProjectContributionStatus::PENDING);

            $units = $this->targetResolver->resolve(
                $project->target_type,
                $project->target_selector,
            );

            $targetUnitIds = $units->pluck('id')->all();

            $isMandatory = $project->participation_type_id === ParticipationType::MANDATORY;

            // Idempotency: find unit IDs already with a contribution for this project.
            $alreadyContributedUnitIds = ProjectContribution::where('community_project_id', $project->id)
                ->pluck('unit_id')
                ->flip()
                ->all();

            // BY_SHARE: prorate the project's goal_amount across the WHOLE set of target
            // units (largest-remainder) so per-unit amounts sum EXACTLY to the goal. The
            // allocation must be computed over all targets at once, not per unit.
            $shareAllocation = $this->computeShareAllocation($project, $units);

            foreach ($units as $unit) {
                $isNew = ! isset($alreadyContributedUnitIds[$unit->id]);

                if ($isNew) {
                    $expectedRaw = $shareAllocation !== null
                        ? ($shareAllocation[$unit->id] ?? 0)
                        : $this->computeExpectedAmount($project);

                    ProjectContribution::create([
                        'community_project_id' => $project->id,
                        'unit_id' => $unit->id,
                        'expected_amount' => $expectedRaw,
                        'contributed_amount' => 0,
                        'declined' => false,
                        'project_contribution_status_id' => $pendingContributionStatus->id,
                    ]);

                    if ($isMandatory && $expectedRaw !== null && $expectedRaw > 0) {
                        $this->createChargeForUnit($project, $unit, $expectedRaw);
                    }
                }
            }

            $project->community_project_status_id = $activeStatus->id;
            $project->save();

            return $project->refresh();
        });

        // §11.10: push to the titular of every target unit that a project was activated.
        // Dispatched AFTER commit so a notification error never rolls back activation.
        $this->notifyProjectActivated($project, $targetUnitIds);

        return $result;
    }

    /**
     * Notify the titular de pago of each target unit that a community project is active.
     *
     * §11.8.7: hidden projects never notify residents.
     *
     * @param  array<int, string>  $targetUnitIds
     */
    private function notifyProjectActivated(CommunityProject $project, array $targetUnitIds): void
    {
        if (! $project->visible_to_residents) {
            return;
        }

        foreach (array_unique($targetUnitIds) as $unitId) {
            $titularId = $this->unitBillingUserId($unitId);
            if (! $titularId) {
                continue;
            }

            $this->notifyUsers(
                [$titularId],
                'Nuevo proyecto comunitario',
                'Se activó el proyecto "'.$project->title.'". Revisa los detalles y cómo participar.',
                [
                    'category' => NotificationCategory::PROJECTS,
                    'click_action' => '/community-project/'.$project->id,
                    'community_project_id' => $project->id,
                    'unit_id' => $unitId,
                ],
            );
        }
    }

    /**
     * Mark a community project as executed and notify all target units (§11.10).
     *
     * FSM: executed is only reachable from ACTIVE or FULLY_FUNDED (§11.8.6 — a
     * project is executed after collection, never straight from a draft).
     *
     * @throws \InvalidArgumentException If project is not active/fully funded.
     */
    public function execute(CommunityProject $project): CommunityProject
    {
        $allowedFrom = [
            CommunityProjectStatus::ACTIVE,
            CommunityProjectStatus::FULLY_FUNDED,
        ];

        if (! in_array($project->community_project_status_id, $allowedFrom, true)) {
            throw new \InvalidArgumentException('Solo se puede ejecutar un proyecto activo o con meta alcanzada.');
        }

        $executedStatus = CommunityProjectStatus::findOrFail(CommunityProjectStatus::EXECUTED);

        $project->community_project_status_id = $executedStatus->id;
        $project->save();

        $this->notifyProjectExecuted($project);

        return $project->refresh();
    }

    /**
     * Cancel a community project (§11.2 — President/Administrator only, enforced by
     * the controller via the cancel.community_project ability).
     *
     * FSM: cancellable from DRAFT, ACTIVE or FULLY_FUNDED. An executed project is
     * history and can no longer be cancelled. No resident notification (§11.10 has
     * no row for cancellation).
     *
     * Cancelling a project also cancels its still-collectible charges so residents
     * stop owing (and aging into overdue) for a project that no longer exists. ONLY
     * charges WITHOUT applied payments are cancelled — a charge that already received
     * money is preserved as history (cancelling it would orphan the payment). The
     * whole transition (charge cancellations + status flip) is atomic.
     *
     * @throws \InvalidArgumentException If project is executed or already cancelled.
     */
    public function cancel(CommunityProject $project): CommunityProject
    {
        if ($project->community_project_status_id === CommunityProjectStatus::EXECUTED) {
            throw new \InvalidArgumentException('No se puede cancelar un proyecto ya ejecutado.');
        }

        if ($project->community_project_status_id === CommunityProjectStatus::CANCELLED) {
            throw new \InvalidArgumentException('El proyecto ya está cancelado.');
        }

        return DB::transaction(function () use ($project): CommunityProject {
            $cancelledStatus = CommunityProjectStatus::findOrFail(CommunityProjectStatus::CANCELLED);

            // Cancel the project's unpaid charges. ChargeService->cancel locks each
            // row and recomputes the unit worst-wins aggregate + batch progress, so
            // the unit is never left frozen in a phantom 'overdue' for a dead project.
            Charge::where('community_project_id', $project->id)
                ->where('is_cancelled', false)
                ->whereDoesntHave('payment_applications')
                ->get()
                ->each(function (Charge $charge): void {
                    $this->chargeService->cancel($charge, 'Proyecto comunitario cancelado.');
                });

            $project->community_project_status_id = $cancelledStatus->id;
            $project->save();

            return $project->refresh();
        });
    }

    /**
     * Push + email all target units' titulares that a project was executed.
     *
     * §11.8.7: hidden projects never notify residents.
     */
    private function notifyProjectExecuted(CommunityProject $project): void
    {
        if (! $project->visible_to_residents) {
            return;
        }

        $unitIds = ProjectContribution::where('community_project_id', $project->id)
            ->whereNull('deleted_at')
            ->pluck('unit_id')
            ->unique()
            ->values()
            ->all();

        foreach ($unitIds as $unitId) {
            $titularId = $this->unitBillingUserId($unitId);
            if (! $titularId) {
                continue;
            }

            $this->notifyUsers(
                [$titularId],
                'Proyecto ejecutado',
                'El proyecto "'.$project->title.'" ha sido ejecutado. Consulta el resumen de avance.',
                [
                    'category' => NotificationCategory::PROJECTS,
                    'click_action' => '/community-project/'.$project->id,
                    'community_project_id' => $project->id,
                    'unit_id' => $unitId,
                ],
                channels: ['log', 'resend_email']
            );
        }
    }

    /**
     * Compute expected amount in minor units based on the project's amount mode.
     * Returns null for free mode (no suggestion) or 0 when no amount is set.
     */
    private function computeExpectedAmount(CommunityProject $project): ?int
    {
        $modeId = $project->amount_mode_id ?? AmountMode::FREE;

        return match ($modeId) {
            AmountMode::FIXED_REQUIRED,
            AmountMode::FIXED_OPTIONAL => (int) ($project->getAttributes()['fixed_amount'] ?? 0),

            AmountMode::RANGE => (int) ($project->getAttributes()['ideal_amount'] ?? $project->getAttributes()['min_amount'] ?? 0),

            AmountMode::FREE => null,

            default => (int) ($project->getAttributes()['fixed_amount'] ?? 0),
        };
    }

    /**
     * For a BY_SHARE project, allocate goal_amount across all target units by
     * ownership_share using the largest-remainder method (zero drift): the per-unit
     * amounts sum EXACTLY to goal_amount when the shares cover 100%.
     *
     * Returns null when the project is NOT by_share (callers fall back to the
     * flat per-unit computeExpectedAmount).
     *
     * Reuses the tested Collections AmountCalculator::computeByShareAllocation by
     * loading the project's persisted ChargeConcept (which carries prorate_total_amount
     * = goal_amount) so the allocation source-of-truth is the goal, never a flat field.
     *
     * @param  iterable<Unit>  $units
     * @return array<string, int>|null unit_id => minor units
     */
    private function computeShareAllocation(CommunityProject $project, iterable $units): ?array
    {
        if (($project->amount_mode_id ?? AmountMode::FREE) !== AmountMode::BY_SHARE) {
            return null;
        }

        $policy = ChargePolicy::findOrFail($this->resolveProjectChargePolicy($project));

        return $this->amountCalculator->computeByShareAllocation($policy, $units);
    }

    /**
     * Create a Charge for a specific unit linked to this community project.
     *
     * Called on activation for MANDATORY projects, and by ContributionService at pay
     * time for VOLUNTARY contributions (§11.8.1 — voluntary charges are created when
     * the money arrives, never upfront).
     */
    public function createChargeForUnit(CommunityProject $project, Unit $unit, int $expectedRaw): Charge
    {
        $pendingStatusId = $this->chargeStatusService->resolveStatusId(ChargeStatus::PENDING);

        $charge = Charge::create([
            'charge_concept_id' => $this->resolveProjectChargeConcept($project),
            'charge_policy_id' => $this->resolveProjectChargePolicy($project),
            'unit_id' => $unit->id,
            'expected_amount' => $expectedRaw,
            'paid_amount' => 0,
            'issued_at' => $project->start_date ?? now()->toDateString(),
            'due_date' => $project->due_date ?? now()->addDays(30)->toDateString(),
            'grace_days' => 0,
            'charge_status_id' => $pendingStatusId,
            'is_cancelled' => false,
            'community_project_id' => $project->id,
        ]);

        // Recompute status (may be overdue for backdated projects).
        $this->chargeStatusService->recomputeCharge($charge, 0);

        return $charge;
    }

    /**
     * Resolve the charge_concept_id (IDENTITY) for a project charge.
     *
     * For v1 we create a disposable concept keyed to the project ('PROJ_{id}').
     * The concept holds only identity (code/name/category); the amount config
     * lives on the sibling ChargePolicy (see resolveProjectChargePolicy).
     */
    private function resolveProjectChargeConcept(CommunityProject $project): string
    {
        $concept = ChargeConcept::where('code', 'PROJ_'.$project->id)->first();

        if ($concept) {
            return $concept->id;
        }

        $concept = ChargeConcept::create([
            'code' => 'PROJ_'.$project->id,
            'name' => $project->title,
            'charge_concept_category_id' => ChargeConceptCategory::idOfKey(ChargeConceptCategory::KEY_PROJECT),
            'is_active' => true,
        ]);

        return $concept->id;
    }

    /**
     * Resolve the charge_policy_id (CONFIG) for a project charge.
     *
     * The disposable policy carries the project's amount mode and amounts so the
     * Collections AmountCalculator can prorate by_share goals (prorate_total_amount =
     * the project's goal_amount). Collection mode is 'direct' (eventual one-shot, no cron).
     */
    private function resolveProjectChargePolicy(CommunityProject $project): string
    {
        $conceptId = $this->resolveProjectChargeConcept($project);

        $policy = ChargePolicy::where('charge_concept_id', $conceptId)->first();

        if ($policy) {
            return $policy->id;
        }

        $policy = ChargePolicy::create([
            'charge_concept_id' => $conceptId,
            'name' => $project->title,
            'amount_mode_id' => $project->amount_mode_id,
            'fixed_amount' => $project->getAttributes()['fixed_amount'] ?? null,
            'min_amount' => $project->getAttributes()['min_amount'] ?? null,
            'ideal_amount' => $project->getAttributes()['ideal_amount'] ?? null,
            'max_amount' => $project->getAttributes()['max_amount'] ?? null,
            // BY_SHARE prorates the project's goal across units; AmountCalculator reads
            // the total to prorate from the policy's prorate_total_amount column.
            'prorate_total_amount' => $project->getAttributes()['goal_amount'] ?? null,
            // EVENTUAL periodicity = a one-shot DIRECT policy (mode is intrinsic to periodicity).
            'periodicity_id' => Periodicity::EVENTUAL,
            'grace_days' => 0,
            'is_active' => true,
        ]);

        return $policy->id;
    }
}
