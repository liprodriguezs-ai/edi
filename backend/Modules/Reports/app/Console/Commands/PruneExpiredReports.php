<?php

namespace Modules\Reports\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Reports\Models\GeneratedReport;
use Modules\Tenant\Models\Tenant;

/**
 * PruneExpiredReports — removes generated_report rows and their associated files
 * that have passed their expires_at timestamp.
 *
 * Scheduled to run daily. Safe to run manually.
 * Does NOT run destructively in tests (use --force in prod).
 */
class PruneExpiredReports extends Command
{
    protected $signature = 'reports:prune-expired';

    protected $description = 'Delete expired generated reports and their stored files (90-day retention).';

    public function handle(): int
    {
        $this->info('Pruning expired generated reports...');

        $deleted = 0;

        // Iterate all tenants to apply TenantScope correctly for each
        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use (&$deleted): void {
                $expired = GeneratedReport::where('expires_at', '<', now())->get();

                foreach ($expired as $report) {
                    if ($report->file_path && Storage::exists($report->file_path)) {
                        Storage::delete($report->file_path);
                    }

                    $report->forceDelete();
                    $deleted++;
                }
            });
        }

        $this->info("Pruned {$deleted} expired generated report(s).");

        return Command::SUCCESS;
    }
}
