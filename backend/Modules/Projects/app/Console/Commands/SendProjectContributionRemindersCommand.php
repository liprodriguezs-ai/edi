<?php

namespace Modules\Projects\Console\Commands;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Notification\Services\NotificationSettingService;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Tenant\Models\Tenant;

/**
 * SendProjectContributionRemindersCommand — daily cron (§11.10 rows 2 and 5).
 *
 * For each tenant, walks ACTIVE, VISIBLE, voluntary projects with dates and reminds
 * the titular de pago of every unit that has NOT contributed (and has not declined):
 *   - "Recordatorio aportación" exactly at the midpoint of [start_date, due_date]
 *   - "Proyecto vence en 7 días" exactly 7 days before due_date
 *
 * Mandatory project charges are already covered by collections:send-reminders;
 * hidden projects never notify residents (§11.8.7). Recipient resolution runs
 * inside $tenant->run() so it is always tenant-scoped.
 */
class SendProjectContributionRemindersCommand extends Command
{
    use NotifiesResidents;

    private const DUE_SOON_DAYS = 7;

    protected $signature = 'projects:send-contribution-reminders';

    protected $description = 'Daily: remind units without a contribution on active voluntary projects (midpoint + 7 days before due).';

    public function handle(): int
    {
        $tenants = Tenant::all();
        $today = Carbon::today();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($today, $tenant): void {
                $sent = $this->sendForTenant($today);
                $label = $tenant->name ?? $tenant->id;
                $this->info("[{$label}] {$sent} project contribution reminder(s) dispatched.");
            });
        }

        $this->info('projects:send-contribution-reminders completed.');

        return self::SUCCESS;
    }

    /**
     * Dispatch reminders for the current tenant. Returns the number sent.
     */
    private function sendForTenant(Carbon $today): int
    {
        // Resolve the per-cluster event config ONCE (channels + is_active) and pass it
        // as $preResolved to every notifyEvent() call. When the cluster disabled the
        // event, skip the whole tenant.
        $resolved = app(NotificationSettingService::class)->resolve('project_contribution_reminder');

        if (! $resolved['is_active']) {
            return 0;
        }

        $projects = CommunityProject::query()
            ->where('participation_type_id', ParticipationType::VOLUNTARY)
            ->where('community_project_status_id', CommunityProjectStatus::ACTIVE)
            ->where('visible_to_residents', true)
            ->whereNotNull('due_date')
            ->get();

        $sent = 0;

        foreach ($projects as $project) {
            $offset = $this->matchedOffset($project, $today);
            if ($offset === null) {
                continue;
            }

            $pendingUnitIds = ProjectContribution::query()
                ->where('community_project_id', $project->id)
                ->where('declined', false)
                ->where('contributed_amount', 0)
                ->pluck('unit_id')
                ->unique();

            foreach ($pendingUnitIds as $unitId) {
                $titularId = $this->unitBillingUserId($unitId);
                if (! $titularId) {
                    continue;
                }

                $billingUser = User::withoutGlobalScope(UserTenantScope::class)->withTrashed()->find($titularId);
                $residentName = $billingUser
                    ? (trim((string) $billingUser->full_name) ?: ($billingUser->name ?? 'Residente'))
                    : 'Residente';
                $dueDateFormatted = Carbon::parse($project->getAttributes()['due_date'])->format('d/m/Y');

                // Configurable event path (channels + templates per cluster): the fixed
                // ['log'] dispatch is retired — channels now come from the cluster's
                // project_contribution_reminder settings. The offset copy stays as the fallback.
                $this->notifyEvent(
                    'project_contribution_reminder',
                    [$titularId],
                    [
                        'resident_name' => $residentName,
                        'project_title' => $project->title,
                        'due_date' => $dueDateFormatted,
                    ],
                    $offset === 'half' ? 'Recordatorio de aportación' : 'El proyecto vence pronto',
                    $this->reminderBody($project, $offset),
                    [
                        'category' => NotificationCategory::PROJECTS,
                        'click_action' => '/community-project/'.$project->id,
                        'community_project_id' => $project->id,
                        'unit_id' => $unitId,
                    ],
                    $resolved,
                );

                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Whether today matches a reminder day for the project: 'half' (midpoint of
     * [start_date, due_date]) or 'due_soon' (7 days before due_date). Null otherwise.
     */
    private function matchedOffset(CommunityProject $project, Carbon $today): ?string
    {
        $dueDate = Carbon::parse($project->getAttributes()['due_date'])->startOfDay();

        if ((int) $today->diffInDays($dueDate, false) === self::DUE_SOON_DAYS) {
            return 'due_soon';
        }

        $startRaw = $project->getAttributes()['start_date'] ?? null;
        if ($startRaw !== null) {
            $startDate = Carbon::parse($startRaw)->startOfDay();
            $totalDays = (int) $startDate->diffInDays($dueDate, false);

            if ($totalDays > 1) {
                $midpoint = $startDate->copy()->addDays(intdiv($totalDays, 2));

                if ($today->isSameDay($midpoint)) {
                    return 'half';
                }
            }
        }

        return null;
    }

    private function reminderBody(CommunityProject $project, string $offset): string
    {
        if ($offset === 'due_soon') {
            return 'El proyecto "'.$project->title.'" vence en 7 días y tu unidad aún no aporta. Aún estás a tiempo de participar.';
        }

        return 'El proyecto "'.$project->title.'" va a la mitad de su plazo y tu unidad aún no aporta. ¡Súmate!';
    }
}
