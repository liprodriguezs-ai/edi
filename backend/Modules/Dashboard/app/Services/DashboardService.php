<?php

namespace Modules\Dashboard\Services;

class DashboardService
{
    /**
     * Onboarding checklist shown on the dashboard.
     *
     * Reset for the Kivo7 domain. The CRM/RRHH steps (collaborators,
     * document templates) were removed with their modules; the real
     * Kivo7 onboarding steps are defined when their modules land.
     *
     * @return array<int, array<string, mixed>>
     */
    public function onboardingSteps(): array
    {
        return [];
    }

    /**
     * Aggregate dashboard summary.
     *
     * Reset for the Kivo7 domain; populated as the functional modules
     * (units, collections, treasury) are implemented.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [];
    }
}
