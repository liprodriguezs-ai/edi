<?php

namespace Modules\Board\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Services\BoardService;
use Modules\Tenant\Models\Tenant;

/**
 * CloseExpiredBoardPeriodsCommand — daily cron (§7.5.1).
 *
 * For each tenant, closes every CURRENT board period whose end_date has passed,
 * revoking the members' global Spatie roles. Without this, an expired board
 * keeps its permissions indefinitely until an admin manually closes the period
 * or starts a replacement.
 */
class CloseExpiredBoardPeriodsCommand extends Command
{
    protected $signature = 'board:close-expired-periods';

    protected $description = 'Daily: close CURRENT board periods with a past end_date and revoke member roles.';

    public function handle(BoardService $boardService): int
    {
        $today = Carbon::today()->toDateString();

        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use ($boardService, $tenant, $today): void {
                $expiredPeriods = BoardPeriod::where('board_period_status_id', BoardPeriodStatus::CURRENT)
                    ->whereDate('end_date', '<', $today)
                    ->get();

                foreach ($expiredPeriods as $period) {
                    $boardService->closePeriod($period);

                    $label = $tenant->name ?? $tenant->id;
                    $this->info("[{$label}] Periodo \"{$period->name}\" cerrado automáticamente (end_date vencida).");
                }
            });
        }

        $this->info('board:close-expired-periods completed.');

        return self::SUCCESS;
    }
}
