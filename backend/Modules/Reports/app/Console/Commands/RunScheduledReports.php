<?php

namespace Modules\Reports\Console\Commands;

use App\Notifications\Concerns\NotifiesResidents;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Reports\Jobs\GenerateReportJob;
use Modules\Reports\Models\GeneratedReport;
use Modules\Reports\Models\ScheduledReport;
use Modules\Tenant\Models\Tenant;

/**
 * RunScheduledReports — daily cron that fires due scheduled reports.
 *
 * Per tenant, finds active scheduled_report rows with next_run_at in the past,
 * creates a GeneratedReport and dispatches GenerateReportJob for each, notifies
 * the configured recipients on completion, then advances next_run_at via
 * computeNextRun() and stamps last_run_at.
 *
 * Tenant-scoped Eloquent only (Tenant::all() + $tenant->run()).
 */
class RunScheduledReports extends Command
{
    use NotifiesResidents;

    protected $signature = 'reports:run-scheduled';

    protected $description = 'Dispatch due scheduled reports per tenant and advance their next_run_at.';

    public function handle(): int
    {
        $dispatched = 0;

        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use (&$dispatched): void {
                $dispatched += $this->runForTenant();
            });
        }

        $this->info("reports:run-scheduled dispatched {$dispatched} scheduled report(s).");

        return self::SUCCESS;
    }

    private function runForTenant(): int
    {
        $due = ScheduledReport::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($due as $scheduled) {
            $this->dispatchScheduled($scheduled);

            $scheduled->last_run_at = now();
            $scheduled->computeNextRun();
            $scheduled->save();
        }

        return $due->count();
    }

    private function dispatchScheduled(ScheduledReport $scheduled): void
    {
        $generatedReport = GeneratedReport::create([
            'report_key' => $scheduled->report_key,
            'format' => $scheduled->format ?? 'pdf',
            'config' => $scheduled->config ?? [],
            'status' => GeneratedReport::STATUS_PENDING,
            'expires_at' => now()->addDays(90),
        ]);

        $recipientUserIds = $this->resolveRecipientUserIds($scheduled);

        GenerateReportJob::dispatch($generatedReport, $recipientUserIds, true);
    }

    /**
     * Resolve recipient user ids from the scheduled report's recipients config.
     * Supports an array of user ids; falls back to the current board members when
     * none are configured so a scheduled report always reaches someone.
     *
     * reports-10: only REAL UUIDs count as user ids (the old `str_contains('-')`
     * heuristic let raw emails through as fake user ids and broke the in-app rows).
     *
     * @return array<int, string>
     */
    private function resolveRecipientUserIds(ScheduledReport $scheduled): array
    {
        $recipients = $scheduled->recipients ?? [];

        $userIds = collect($recipients)
            ->filter(fn ($value): bool => is_string($value) && Str::isUuid($value))
            ->values()
            ->all();

        if ($userIds !== []) {
            return $userIds;
        }

        return $this->currentBoardMemberUserIds();
    }
}
