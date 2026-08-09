<?php

namespace Modules\Projects\Observers;

use Modules\Collections\Models\PaymentApplication;
use Modules\Projects\Services\ContributionService;

/**
 * ProjectChargeApplicationObserver — accounting bridge Collections → Projects.
 *
 * Registered from ProjectsServiceProvider (Collections code is NOT modified). Every
 * payment application that touches a charge linked to a community project rolls the
 * applied amount up into the matching project_contribution (§11.9 Flujo A paso 3:
 * "paga → cargo completo, aportación marcada completa"), inside the SAME transaction
 * PaymentService opened — payment, charge and contribution commit or roll back together.
 *
 * Events covered:
 *   created → +applied_amount (register/validate payment)
 *   updated → applied_amount delta (credit clawback shrinks applications)
 *   deleted → -applied_amount (payment cancellation reverses applications)
 */
class ProjectChargeApplicationObserver
{
    public function created(PaymentApplication $application): void
    {
        $this->sync($application, (int) $application->getAttributes()['applied_amount']);
    }

    public function updated(PaymentApplication $application): void
    {
        if (! $application->wasChanged('applied_amount')) {
            return;
        }

        $delta = (int) $application->getAttributes()['applied_amount']
            - (int) $application->getRawOriginal('applied_amount');

        $this->sync($application, $delta);
    }

    public function deleted(PaymentApplication $application): void
    {
        $this->sync($application, -((int) $application->getAttributes()['applied_amount']));
    }

    private function sync(PaymentApplication $application, int $deltaRaw): void
    {
        app(ContributionService::class)->syncFromChargeApplication($application, $deltaRaw);
    }
}
