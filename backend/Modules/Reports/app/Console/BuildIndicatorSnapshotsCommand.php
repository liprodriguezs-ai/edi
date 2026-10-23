<?php

namespace Modules\Reports\Console;

use Illuminate\Console\Command;
use Modules\Reports\Services\IndicatorSnapshotService;
use Modules\Tenant\Models\Tenant;
use Throwable;

/**
 * reports:build-indicator-snapshots -- nightly convergence of the screening
 * indicator snapshots (design D6, REQ-RPT-003). Per tenant, trailing window
 * (default 3 months) OR one exact --period; a failure in one tenant is
 * logged and never aborts the remaining tenants.
 */
class BuildIndicatorSnapshotsCommand extends Command
{
    protected $signature = 'reports:build-indicator-snapshots {--tenant=} {--period=} {--months=3}';

    protected $description = 'Upsert screening_indicator_snapshot rows per tenant (trailing window or one --period), idempotent.';

    public function handle(IndicatorSnapshotService $service): int
    {
        $tenantId = $this->option('tenant');
        $period = $this->option('period');
        $months = (int) $this->option('months');

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            try {
                if ($period !== null) {
                    $service->buildPeriod($period);
                } else {
                    $service->buildTrailingWindow($months);
                }

                $this->info(sprintf('[%s] snapshots built.', $tenant->name ?? $tenant->id));
            } catch (Throwable $e) {
                $this->error(sprintf('[%s] snapshot build failed: %s', $tenant->name ?? $tenant->id, $e->getMessage()));

                report($e);
            } finally {
                tenancy()->end();
            }
        }

        $this->info('reports:build-indicator-snapshots completed.');

        return self::SUCCESS;
    }
}
