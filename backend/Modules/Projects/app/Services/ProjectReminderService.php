<?php

namespace Modules\Projects\Services;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Modules\Notification\Services\NotificationSettingService;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\ProjectContribution;

/**
 * ProjectReminderService — manual "send reminder" for a voluntary project.
 *
 * Notifies the titular de pago of every unit whose contribution is still pending
 * (not declined, nothing contributed yet) through the configurable
 * project_contribution_reminder event. The board triggers it on demand; the daily
 * cron reuses the same event.
 */
class ProjectReminderService
{
    use NotifiesResidents;

    public const EVENT_KEY = 'project_contribution_reminder';

    /**
     * Notify the billing owners of units with a pending contribution on this project.
     * Returns the number of units a reminder was dispatched to (0 when the event is
     * disabled for the cluster).
     */
    public function sendPendingReminders(CommunityProject $project): int
    {
        // Resolve the per-cluster event config ONCE and pass it as $preResolved to
        // every notifyEvent() call (mirrors SendChargeRemindersCommand — arch W1).
        $resolved = app(NotificationSettingService::class)->resolve(self::EVENT_KEY);

        if (! $resolved['is_active']) {
            return 0;
        }

        $pendingUnitIds = ProjectContribution::query()
            ->where('community_project_id', $project->id)
            ->where('declined', false)
            ->where('contributed_amount', 0)
            ->pluck('unit_id')
            ->unique();

        $dueDateFormatted = $project->due_date instanceof Carbon
            ? $project->due_date->format('d/m/Y')
            : '';

        $sent = 0;

        foreach ($pendingUnitIds as $unitId) {
            $titularId = $this->unitBillingUserId($unitId);
            if (! $titularId) {
                continue;
            }

            $billingUser = User::withoutGlobalScope(UserTenantScope::class)->withTrashed()->find($titularId);
            $residentName = $billingUser
                ? (trim((string) $billingUser->full_name) ?: ($billingUser->name ?? 'Residente'))
                : 'Residente';

            $this->notifyEvent(
                self::EVENT_KEY,
                [$titularId],
                [
                    'resident_name' => $residentName,
                    'project_title' => $project->title,
                    'due_date' => $dueDateFormatted,
                ],
                'Recordatorio de aportación',
                'El proyecto "'.$project->title.'" sigue abierto y tu unidad aún no aporta.',
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

        return $sent;
    }
}
