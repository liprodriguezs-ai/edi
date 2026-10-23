<?php

declare(strict_types=1);

namespace Modules\FollowUp\Console;

use Illuminate\Console\Command;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;

/**
 * follow-up:refresh-overdue -- daily convergence of the derived `overdue`
 * state (design D7, REQ-FUP-005). Per tenant, both directions:
 * pending -> overdue (past due_date) and overdue -> pending (rescheduled
 * into the future). NEVER touches in_progress/completed/cancelled.
 * Idempotent: an immediate second run changes zero rows.
 *
 * "Today" is each tenant's civil day (TenantTime, tenant.timezone), so the
 * command is scheduled hourly: every tenant's midnight falls inside some run,
 * and runs that find nothing to change are no-ops.
 */
class RefreshOverdueFollowUpsCommand extends Command
{
    protected $signature = 'follow-up:refresh-overdue';

    protected $description = 'Hourly: converge follow_up.follow_up_status_id between pending and overdue based on due_date vs each tenant\'s civil today.';

    public function handle(): int
    {
        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);
            $today = TenantTime::today();

            $toOverdue = FollowUp::query()
                ->where('follow_up_status_id', FollowUpStatus::PENDING)
                ->whereDate('due_date', '<', $today)
                ->update(['follow_up_status_id' => FollowUpStatus::OVERDUE]);

            $toPending = FollowUp::query()
                ->where('follow_up_status_id', FollowUpStatus::OVERDUE)
                ->whereDate('due_date', '>=', $today)
                ->update(['follow_up_status_id' => FollowUpStatus::PENDING]);

            if ($toOverdue > 0 || $toPending > 0) {
                $this->info(sprintf(
                    '[%s] %d pendiente(s) -> vencido, %d vencido(s) -> pendiente.',
                    $tenant->name ?? $tenant->id,
                    $toOverdue,
                    $toPending,
                ));
            }

            tenancy()->end();
        }

        $this->info('follow-up:refresh-overdue completed.');

        return self::SUCCESS;
    }
}
