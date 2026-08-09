<?php

namespace Modules\Projects\Handlers;

use Modules\Collections\Contracts\PaymentEarmarkHandler;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\Unit;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Services\ProjectActivationService;

/**
 * ContributionEarmarkHandler — earmarked payments that fund a project contribution.
 *
 * THE single place that creates a project charge from a payment (§11.8.1): the
 * voluntary-project money path for BOTH flows — admin direct capture
 * (payment/register + earmark → confirmed at once) and resident report
 * (payment/submit + earmark → PENDING_VALIDATION → treasurer validates).
 * Exact parity with the retired ContributionService::contribute($paymentData)
 * branch: the charge is created only when the payment is CONFIRMED, pinned for
 * the full amount, and the ProjectChargeApplicationObserver rolls the
 * application up into contributed_amount/status/fully_funded. This handler
 * never notifies — the observer and the payment flow own the notifications.
 */
class ContributionEarmarkHandler implements PaymentEarmarkHandler
{
    public const EARMARK_TYPE = 'project_contribution';

    /**
     * {@inheritDoc}
     */
    public function validateSubmission(array $data, bool $isSelfSubmission = false): void
    {
        $contribution = ProjectContribution::with('project.status')->find($data['earmark_id'] ?? null);

        if (! $contribution) {
            throw new \InvalidArgumentException('No existe la aportación indicada para este proyecto.');
        }

        if (($data['unit_id'] ?? null) !== $contribution->unit_id) {
            throw new \InvalidArgumentException('La aportación no corresponde a la unidad del pago.');
        }

        $this->assertProjectAcceptsEarmarkedMoney($contribution->project);

        if ((bool) $contribution->declined) {
            throw new \InvalidArgumentException('La aportación fue declinada. Reviértela antes de reportar un pago.');
        }

        // Friendly guard against a duplicate report; the partial unique index
        // payment_one_pending_per_earmark is the race-proof lock behind it.
        $hasPendingReport = Payment::query()
            ->where('earmark_type', self::EARMARK_TYPE)
            ->where('earmark_id', $contribution->id)
            ->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)
            ->exists();

        if ($hasPendingReport) {
            throw new \InvalidArgumentException('Ya hay un pago pendiente de validación para esta aportación. Espera su revisión.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function resolveApplications(Payment $payment): array
    {
        $contribution = ProjectContribution::query()->lockForUpdate()->findOrFail($payment->earmark_id);
        $contribution->load('project.status');

        // Defensive re-check of the payment↔contribution binding: today earmark columns
        // are immutable post-submit (the payment Orion resource is read-only), but the
        // handler must not depend on that route configuration staying true.
        if ($contribution->unit_id !== $payment->unit_id) {
            throw new \InvalidArgumentException('El pago no corresponde a la unidad de la aportación.');
        }

        // Re-verify under lock: the project may have changed since the submit.
        $this->assertProjectAcceptsEarmarkedMoney($contribution->project);

        $totalAmountRaw = (int) $payment->getAttributes()['total_amount'];

        $charge = app(ProjectActivationService::class)->createChargeForUnit(
            $contribution->project,
            Unit::findOrFail($contribution->unit_id),
            $totalAmountRaw,
        );

        return [
            ['charge_id' => $charge->id, 'apply_amount' => $totalAmountRaw],
        ];
    }

    /**
     * A contribution can only be funded on a VOLUNTARY project that is still
     * collecting (ACTIVE or FULLY_FUNDED — over-funding is allowed, §11.8.4).
     *
     * @throws \InvalidArgumentException
     */
    private function assertProjectAcceptsEarmarkedMoney(CommunityProject $project): void
    {
        if ($project->participation_type_id === ParticipationType::MANDATORY) {
            throw new \InvalidArgumentException('Las aportaciones de un proyecto obligatorio se pagan a través de su cargo.');
        }

        $contributableStatuses = [CommunityProjectStatus::ACTIVE, CommunityProjectStatus::FULLY_FUNDED];

        if (! in_array($project->community_project_status_id, $contributableStatuses, true)) {
            throw new \InvalidArgumentException('El proyecto no está activo. No se aceptan aportaciones.');
        }
    }
}
