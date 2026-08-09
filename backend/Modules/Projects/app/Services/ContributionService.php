<?php

namespace Modules\Projects\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\PaymentApplication;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;
use QCH\Core\Support\TenantMoney;

/**
 * ContributionService — manages voluntary contributions and project rollups.
 *
 * Contribution status logic (voluntary mode with min/ideal/max):
 *   contributed = 0                              → pending
 *   0 < contributed < min                        → below_min
 *   min <= contributed < ideal                   → partial
 *   contributed == ideal                         → ideal
 *   contributed > ideal                          → generous
 *   declined = true                              → declined (regardless of amount)
 *
 * Rollups (computed on demand):
 *   recaudado  = sum(contribution.contributed_amount)
 *   ejecutado  = sum(expense.amount WHERE community_project_id = project.id)
 *   disponible = recaudado - ejecutado
 *
 * fully_funded: project is marked fully_funded when recaudado >= goal_amount.
 *
 * ACCOUNTING BRIDGE (§11.8.1, §11.9 Flujo B paso 4):
 *   - Voluntary contribution MONEY travels as earmarked payments (payment/register
 *     or payment/submit with earmark_type=project_contribution): on confirmation,
 *     ContributionEarmarkHandler creates the eventual project Charge and pins the
 *     full amount to it — never as an untracked increment, never FIFO.
 *   - The PaymentApplication observer (ProjectChargeApplicationObserver) routes every
 *     application on a project-linked charge into syncFromChargeApplication(), so
 *     paying a MANDATORY project charge also rolls up into project_contribution.
 *
 * CONCURRENCY: every mutation re-reads the contribution row with lockForUpdate()
 *   inside the transaction (same pattern as PaymentService) — no lost updates.
 */
class ContributionService
{
    use NotifiesResidents;

    private function formatMoney(int $amountRaw): string
    {
        return TenantMoney::format($amountRaw);
    }

    /**
     * Record a manual contribution adjustment (no money movement).
     *
     * Voluntary contributions are added directly; for MANDATORY projects the amount
     * is NOT added — the contribution is re-synced from the project charge's payment
     * applications (the charge is the source of truth for mandatory money), which
     * makes the call idempotent.
     *
     * Contribution MONEY never enters here: it travels as an earmarked payment
     * (payment/register or payment/submit with earmark_type=project_contribution),
     * whose ContributionEarmarkHandler creates the project charge on confirmation and
     * rolls up via ProjectChargeApplicationObserver → syncFromChargeApplication().
     *
     * @param  ProjectContribution  $contribution  The unit's contribution record.
     * @param  int  $amountRaw  Minor units to add.
     *
     * @throws \InvalidArgumentException If project is closed/cancelled or the amount is invalid.
     */
    public function contribute(ProjectContribution $contribution, int $amountRaw): ProjectContribution
    {
        if ($amountRaw <= 0) {
            throw new \InvalidArgumentException('El monto de la aportación debe ser mayor a cero.');
        }

        $goalJustReached = false;

        $result = DB::transaction(function () use ($contribution, $amountRaw, &$goalJustReached): ProjectContribution {
            $locked = $this->lockContribution($contribution->id);
            $project = $locked->project;

            $this->assertProjectAcceptsContributions($project);

            $isMandatory = $project->participation_type_id === ParticipationType::MANDATORY;

            $current = (int) $locked->getAttributes()['contributed_amount'];
            $newTotal = $isMandatory
                ? $this->sumChargeApplications($project->id, $locked->unit_id)
                : $current + $amountRaw;

            $this->applyContributionTotal($locked, $project, $newTotal, undecline: true);

            $goalJustReached = $this->recomputeFullyFunded($project);

            return $locked->refresh();
        });

        // §11.10: notify AFTER commit so a notification error never rolls back the
        // contribution. Push to the contributor (unit's titular), and push+email to all
        // target units when the project reaches its goal.
        $titularId = $this->unitBillingUserId($result->unit_id);
        if ($titularId) {
            $this->notifyUsers(
                [$titularId],
                'Aportación registrada',
                'Se registró tu aportación de '.$this->formatMoney($amountRaw).' al proyecto comunitario.',
                [
                    'category' => NotificationCategory::PROJECTS,
                    'click_action' => '/community-project/'.$result->community_project_id,
                    'project_contribution_id' => $result->id,
                    'unit_id' => $result->unit_id,
                ],
            );
        }

        if ($goalJustReached) {
            $this->notifyGoalReached($result->project);
        }

        return $result;
    }

    /**
     * Sync a contribution from a payment application on a project-linked charge.
     *
     * Called by ProjectChargeApplicationObserver INSIDE the payment transaction:
     *   - mandatory projects → contributed_amount is recomputed from the full sum of
     *     applications on the unit's project charges (idempotent source of truth);
     *   - voluntary projects → the signed delta is applied (direct contributions made
     *     without a charge trail are preserved).
     *
     * @param  int  $deltaRaw  Signed minor units (negative on cancel/clawback).
     */
    public function syncFromChargeApplication(PaymentApplication $application, int $deltaRaw): void
    {
        $charge = $application->charge;

        if (! $charge || ! $charge->community_project_id || $deltaRaw === 0) {
            return;
        }

        $contribution = ProjectContribution::query()
            ->where('community_project_id', $charge->community_project_id)
            ->where('unit_id', $charge->unit_id)
            ->lockForUpdate()
            ->first();

        if (! $contribution) {
            return;
        }

        $project = CommunityProject::with('status')->findOrFail($charge->community_project_id);

        $isMandatory = $project->participation_type_id === ParticipationType::MANDATORY;
        $current = (int) $contribution->getAttributes()['contributed_amount'];

        $newTotal = $isMandatory
            ? $this->sumChargeApplications($project->id, $charge->unit_id)
            : max(0, $current + $deltaRaw);

        $this->applyContributionTotal($contribution, $project, $newTotal, undecline: $deltaRaw > 0);

        if ($this->recomputeFullyFunded($project)) {
            // notifyUsers defers via DB::afterCommit, so firing inside the payment
            // transaction is safe and never rolls the payment back.
            $this->notifyGoalReached($project);
        }
    }

    /**
     * Persist a new contributed total + derived status on a locked contribution row.
     */
    private function applyContributionTotal(
        ProjectContribution $contribution,
        CommunityProject $project,
        int $newTotalRaw,
        bool $undecline,
    ): void {
        $contribution->contributed_amount = $newTotalRaw;

        if ($undecline) {
            $contribution->declined = false;
        }

        $contribution->project_contribution_status_id = $contribution->declined
            ? ProjectContributionStatus::DECLINED
            : $this->resolveContributionStatusId($project, $newTotalRaw);

        $contribution->save();
    }

    /**
     * Sum of payment applications (minor units) on the unit's non-cancelled charges
     * linked to the project — the contributed source of truth for mandatory projects.
     */
    private function sumChargeApplications(string $projectId, string $unitId): int
    {
        return (int) PaymentApplication::query()
            ->whereHas('charge', fn ($query) => $query
                ->where('community_project_id', $projectId)
                ->where('unit_id', $unitId)
                ->where('is_cancelled', false))
            ->sum('applied_amount');
    }

    /**
     * Re-read a contribution row with a row lock inside the current transaction.
     */
    private function lockContribution(string $contributionId): ProjectContribution
    {
        $locked = ProjectContribution::query()->lockForUpdate()->findOrFail($contributionId);
        $locked->load('project.status');

        return $locked;
    }

    /**
     * Push + email all target units' titulares that a project reached its funding goal.
     *
     * §11.8.7: hidden projects never notify residents.
     */
    private function notifyGoalReached(CommunityProject $project): void
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
                'Meta de proyecto alcanzada',
                'El proyecto "'.$project->title.'" alcanzó su meta de financiamiento. ¡Gracias por participar!',
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
     * Mark a voluntary contribution as declined.
     *
     * §11.4 / §11.8.2: declining only exists for VOLUNTARY projects, and only when
     * the project allows it (allow_decline). Mandatory units that don't pay are
     * delinquent, never "declined".
     *
     * @throws \InvalidArgumentException If project is closed/cancelled, mandatory,
     *                                   or does not allow declining.
     */
    public function decline(ProjectContribution $contribution): ProjectContribution
    {
        return DB::transaction(function () use ($contribution): ProjectContribution {
            $locked = $this->lockContribution($contribution->id);
            $project = $locked->project;

            $this->assertProjectAcceptsContributions($project);

            if ($project->participation_type_id === ParticipationType::MANDATORY) {
                throw new \InvalidArgumentException('Los proyectos obligatorios no se pueden declinar.');
            }

            if (! $project->allow_decline) {
                throw new \InvalidArgumentException('Este proyecto no permite declinar la aportación.');
            }

            $locked->declined = true;
            $locked->project_contribution_status_id = ProjectContributionStatus::DECLINED;
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * Revert a declined contribution back to pending (resident changed their mind).
     */
    public function revertDecline(ProjectContribution $contribution): ProjectContribution
    {
        return DB::transaction(function () use ($contribution): ProjectContribution {
            $locked = $this->lockContribution($contribution->id);
            $this->assertProjectAcceptsContributions($locked->project);

            $locked->declined = false;
            $current = (int) $locked->getAttributes()['contributed_amount'];
            $locked->project_contribution_status_id = $this->resolveContributionStatusId($locked->project, $current);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * Get rollup summary for a project (raised, executed, available).
     *
     * Returns RAW minor-unit integers — the caller (CommunityProjectController::summary)
     * wraps each field with TenantMoney::make() at the JSON boundary.
     *
     * @return array{raised: int, executed: int, available: int}
     */
    public function getSummary(CommunityProject $project): array
    {
        $raised = (int) ProjectContribution::where('community_project_id', $project->id)
            ->whereNull('deleted_at')
            ->sum('contributed_amount');

        // Exclude VOIDED expenses: void() only flips the status (no soft delete), so a
        // voided expense must not count toward executed/available.
        $voidedStatusId = ExpenseStatus::VOIDED;

        $executed = (int) Expense::where('community_project_id', $project->id)
            ->whereNull('deleted_at')
            ->when($voidedStatusId !== null, fn ($q) => $q->where('expense_status_id', '!=', $voidedStatusId))
            ->sum('amount');

        // available = raised − executed, returned RAW (may be negative). A negative
        // value is a valid business state: it signals the project over-executed (spent
        // more than it collected). Clamping it to 0 would mask that deficit from any
        // consumer that trusts `available`, contradicting this method's contract.
        $available = $raised - $executed;

        return [
            'raised' => $raised,
            'executed' => $executed,
            'available' => $available,
        ];
    }

    /**
     * Recompute fully_funded status: update project to fully_funded if
     * recaudado >= goal_amount (and project is still active).
     *
     * SEMANTICS (intentional, documented):
     *  - Contributions remain ALLOWED after a project becomes fully_funded — over-funding is
     *    acceptable; assertProjectAcceptsContributions only blocks EXECUTED/CANCELLED.
     *  - fully_funded LATCHES: this method only transitions ACTIVE → fully_funded. It does NOT
     *    auto-revert fully_funded → active if recaudado later drops below the goal (e.g. a
     *    contribution correction). A revert, if ever needed, is an explicit admin action.
     *
     * @return bool True when this call flipped the project ACTIVE → fully_funded (used by
     *              callers to fire the §11.10 "goal reached" notification exactly once).
     */
    public function recomputeFullyFunded(CommunityProject $project): bool
    {
        $project->load('status');
        $goalRaw = (int) ($project->getAttributes()['goal_amount'] ?? 0);

        if ($goalRaw <= 0) {
            return false;
        }

        if ($project->community_project_status_id !== CommunityProjectStatus::ACTIVE) {
            return false;
        }

        $recaudado = (int) ProjectContribution::where('community_project_id', $project->id)
            ->whereNull('deleted_at')
            ->sum('contributed_amount');

        if ($recaudado >= $goalRaw) {
            $fullyFundedStatus = CommunityProjectStatus::find(CommunityProjectStatus::FULLY_FUNDED);
            if ($fullyFundedStatus) {
                $project->community_project_status_id = $fullyFundedStatus->id;
                $project->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the contribution status ID based on amount and project amount mode.
     */
    private function resolveContributionStatusId(CommunityProject $project, int $contributedRaw): string
    {
        $modeId = $project->amount_mode_id ?? AmountMode::FREE;

        // The returned status constant IS the UUID id (central catalog).
        return $this->resolveContributionStatusKey($modeId, $project, $contributedRaw);
    }

    private function resolveContributionStatusKey(string $modeId, CommunityProject $project, int $contributedRaw): string
    {
        if ($contributedRaw <= 0) {
            return ProjectContributionStatus::PENDING;
        }

        if ($modeId === AmountMode::RANGE) {
            $minRaw = (int) ($project->getAttributes()['min_amount'] ?? 0);
            $idealRaw = (int) ($project->getAttributes()['ideal_amount'] ?? 0);

            if ($contributedRaw < $minRaw) {
                return ProjectContributionStatus::BELOW_MIN;
            }

            if ($contributedRaw < $idealRaw) {
                return ProjectContributionStatus::PARTIAL;
            }

            if ($contributedRaw === $idealRaw) {
                return ProjectContributionStatus::IDEAL;
            }

            return ProjectContributionStatus::GENEROUS;
        }

        if ($modeId === AmountMode::FIXED_REQUIRED || $modeId === AmountMode::FIXED_OPTIONAL) {
            $fixedRaw = (int) ($project->getAttributes()['fixed_amount'] ?? 0);

            if ($contributedRaw < $fixedRaw) {
                return ProjectContributionStatus::PARTIAL;
            }

            if ($contributedRaw === $fixedRaw) {
                return ProjectContributionStatus::IDEAL;
            }

            return ProjectContributionStatus::GENEROUS;
        }

        // Free mode: any contribution is "ideal"
        return ProjectContributionStatus::IDEAL;
    }

    /**
     * Assert the project still accepts contributions (not closed/cancelled/executed).
     *
     * @throws \InvalidArgumentException
     */
    private function assertProjectAcceptsContributions(CommunityProject $project): void
    {
        $closedKeys = [
            CommunityProjectStatus::EXECUTED,
            CommunityProjectStatus::CANCELLED,
        ];

        if (in_array($project->community_project_status_id, $closedKeys, true)) {
            throw new \InvalidArgumentException(
                'El proyecto está cerrado o cancelado. No se aceptan aportaciones.'
            );
        }
    }
}
