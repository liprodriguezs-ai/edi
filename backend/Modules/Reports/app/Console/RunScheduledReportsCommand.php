<?php

namespace Modules\Reports\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Reports\Models\ReportFrequency;
use Modules\Reports\Models\ScheduledReport;
use Modules\Reports\Services\ScheduledReportRunner;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;

/**
 * Nightly runner for saved reports (REQ-RPT-013).
 *
 * Iterates organizations and switches into each one before touching a row, so a
 * generated report can never span organizations — the tenant scope is what makes
 * "this organization's screenings" mean anything, and it only applies once
 * tenancy is initialized.
 *
 * Idempotent per period: due-ness is derived from `last_run_at` against the
 * cadence, so a cron retry, an overlapping run, or a manual invocation on the
 * same day delivers nothing extra.
 */
class RunScheduledReportsCommand extends Command
{
    protected $signature = 'reports:run-scheduled';

    protected $description = 'Genera y entrega los reportes programados que corresponden a hoy.';

    public function handle(ScheduledReportRunner $runner): int
    {
        $delivered = 0;
        $failed = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $schedules = ScheduledReport::query()
                    ->where('is_active', true)
                    ->with(['report_frequency', 'recipient'])
                    ->get();

                foreach ($schedules as $schedule) {
                    if (! $this->isDue($schedule)) {
                        continue;
                    }

                    try {
                        $runner->run($schedule);
                        $delivered++;
                    } catch (\Throwable $e) {
                        // One broken schedule must not stop the rest of the night:
                        // report it and carry on to the next organization.
                        $failed++;
                        report($e);
                        Log::warning('[reports:run-scheduled] Schedule failed.', [
                            'scheduled_report_id' => $schedule->id,
                            'tenant_id' => $tenant->getTenantKey(),
                        ]);
                    }
                }
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Reportes entregados: {$delivered}. Con error: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Due-ness from the stored cadence, never from a wall-clock schedule: the
     * command may run late, twice, or be replayed by hand. Periods are the
     * tenant's civil day/week/month (TenantTime); comparisons are by instant.
     */
    private function isDue(ScheduledReport $schedule): bool
    {
        $lastRun = $schedule->last_run_at;

        if ($lastRun === null) {
            return true;
        }

        $tenantNow = TenantTime::inTenantZone(now());

        return match ($schedule->report_frequency_id) {
            ReportFrequency::DAILY => $lastRun->lt($tenantNow->startOfDay()),
            ReportFrequency::WEEKLY => $lastRun->lt($tenantNow->startOfWeek()),
            ReportFrequency::MONTHLY => $lastRun->lt($tenantNow->startOfMonth()),
            // An unknown cadence is not a reason to spam the recipient nightly.
            default => false,
        };
    }
}
