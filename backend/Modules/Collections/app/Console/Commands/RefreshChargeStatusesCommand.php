<?php

namespace Modules\Collections\Console\Commands;

use Illuminate\Console\Command;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Tenant\Models\Tenant;

/**
 * RefreshChargeStatusesCommand — daily cron.
 *
 * For each tenant, recomputes time-based charge status transitions:
 *   pending → due_soon (N days before due_date)
 *   pending/partial/due_soon → overdue (after due_date + grace_days)
 *
 * Also recomputes unit aggregate charge_status_id (worst-wins).
 */
class RefreshChargeStatusesCommand extends Command
{
    protected $signature = 'collections:refresh-statuses';

    protected $description = 'Daily: recompute time-based charge status transitions for all tenants.';

    public function handle(ChargeStatusService $chargeStatusService): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($chargeStatusService, $tenant): void {
                $label = $tenant->name ?? $tenant->id;
                $chargeStatusService->refreshAllOpenCharges();
                $this->info("[{$label}] Charge statuses refreshed.");
            });
        }

        $this->info('collections:refresh-statuses completed.');

        return self::SUCCESS;
    }
}
