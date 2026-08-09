<?php

namespace Modules\Access\Console\Commands;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Access\Models\AccessLog;
use Modules\Tenant\Models\Tenant;

/**
 * SendLongStayAlertsCommand — hourly cron (§14.7).
 *
 * For each tenant, finds visitors still inside (entered_at set, exited_at null) for more
 * than the configured threshold (default 8h) and alerts the guards + the unit's residents.
 */
class SendLongStayAlertsCommand extends Command
{
    use NotifiesResidents;

    /** Hours after which an open access log is considered a long stay. */
    private const LONG_STAY_HOURS = 8;

    protected $signature = 'access:long-stay-alerts';

    protected $description = 'Hourly: alert guards + residents of visitors inside > 8h without checkout, per tenant.';

    public function handle(): int
    {
        $tenants = Tenant::all();
        $cutoff = Carbon::now()->subHours(self::LONG_STAY_HOURS);

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($cutoff, $tenant): void {
                $count = $this->alertForTenant($cutoff);
                $label = $tenant->name ?? $tenant->id;
                $this->info("[{$label}] {$count} long-stay alert(s) dispatched.");
            });
        }

        $this->info('access:long-stay-alerts completed.');

        return self::SUCCESS;
    }

    private function alertForTenant(Carbon $cutoff): int
    {
        // Sliding one-hour window (the command is hourly): each open visit crosses
        // the 8h threshold exactly once, so the SAME visit is not re-alerted on
        // every later run (a 3-day open visit would otherwise spam ~64 pushes).
        $openLogs = AccessLog::query()
            ->whereNull('exited_at')
            ->whereNotNull('entered_at')
            ->where('entered_at', '<=', $cutoff)
            ->where('entered_at', '>', $cutoff->copy()->subHour())
            ->get();

        $guardIds = $this->userIdsWithRole('security');
        $count = 0;

        foreach ($openLogs as $log) {
            $residentIds = $log->unit_id ? $this->unitResidentUserIds($log->unit_id) : [];
            $recipients = array_values(array_unique(array_merge($guardIds, $residentIds)));

            if ($recipients === []) {
                continue;
            }

            $this->notifyUsers(
                $recipients,
                'Visitante con estancia prolongada',
                "El visitante '{$log->visitor_name}' lleva más de ".self::LONG_STAY_HOURS.' horas sin registrar salida.',
                [
                    'click_action' => '/access-log',
                    'access_log_id' => $log->id,
                    'unit_id' => $log->unit_id,
                    'category' => NotificationCategory::ACCESS,
                ],
            );

            $count++;
        }

        return $count;
    }
}
