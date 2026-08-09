<?php

namespace Modules\Board\Services;

use App\Models\Role as AppRole;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Announcements\Services\AnnouncementService;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Notification\Services\NotificationSettingService;

class BoardService
{
    use NotifiesResidents;

    /**
     * Start a new board period, optionally auto-closing any current period.
     *
     * @param  array  $periodData  Keys: name, start_date, end_date, notes, auto_close_current
     * @param  array  $membersData  Array of ['user_id' => uuid, 'board_role_id' => uuid, 'unit_id' => uuid|null]
     */
    public function startPeriod(array $periodData, array $membersData): BoardPeriod
    {
        return DB::transaction(function () use ($periodData, $membersData): BoardPeriod {
            $currentStatus = BoardPeriodStatus::findOrFail(BoardPeriodStatus::CURRENT);

            // Mandatory offices (spec §7.3.2): a period must include at least one
            // President, one Treasurer and one Secretary; vocales/oversight are optional.
            $this->assertMandatoryRolesPresent($membersData);

            // §7.5.1/§7.6: the current period (if any) is auto-closed BEFORE the
            // overlap assert, truncating its end_date to the day before the new
            // period starts so mandates never overlap on record.
            $currentPeriod = BoardPeriod::where('board_period_status_id', $currentStatus->id)->first();

            if ($currentPeriod !== null) {
                if (empty($periodData['auto_close_current'])) {
                    throw new \InvalidArgumentException(
                        'Ya existe un periodo vigente. Use auto_close_current=true para cerrarlo automáticamente.'
                    );
                }

                $this->closePeriod(
                    $currentPeriod,
                    Carbon::parse($periodData['start_date'])->subDay()->toDateString(),
                );
            }

            // §7.3.2: dates must not overlap another CURRENT period. Closed periods
            // never block a new mandate (their end_date is truncated on early close).
            $this->assertNoOverlappingPeriod($periodData['start_date'], $periodData['end_date']);

            $period = BoardPeriod::create([
                'name' => $periodData['name'],
                'start_date' => $periodData['start_date'],
                'end_date' => $periodData['end_date'],
                'board_period_status_id' => $currentStatus->id,
                'notes' => $periodData['notes'] ?? null,
            ]);

            foreach ($membersData as $memberData) {
                $member = BoardMember::create([
                    'board_period_id' => $period->id,
                    'user_id' => $memberData['user_id'],
                    'board_role_id' => $memberData['board_role_id'],
                    'unit_id' => $memberData['unit_id'] ?? null,
                ]);

                $this->grantSpatieRole($member);
            }

            // §7.7: notify the newly-assigned members + announce the new board to all
            // residents. Dispatched AFTER commit so a notification error never rolls back
            // the period creation. The auto-announcement is also created post-commit.
            $this->notifyBoardStarted($period, $membersData);

            return $period->load('members.board_role', 'board_period_status');
        });
    }

    /**
     * §7.7 notifications for a newly-started board period:
     *  - SMS+email to each assigned member ("Has sido asignado a la mesa directiva").
     *  - push to ALL residents ("Nueva mesa directiva").
     *  - auto-create a board announcement ("Nueva mesa directiva YYYY-YYYY").
     *
     * @param  array<int, array{user_id: string, board_role_id: string, unit_id?: string|null}>  $membersData
     */
    private function notifyBoardStarted(BoardPeriod $period, array $membersData): void
    {
        $memberUserIds = array_values(array_unique(array_column($membersData, 'user_id')));

        // Assigned-to-seat: each member gets an SMS + email.
        $this->notifyUsers(
            $memberUserIds,
            'Asignación a la mesa directiva',
            'Has sido asignado a la mesa directiva del periodo '.$period->name.'.',
            ['click_action' => '/board-period', 'board_period_id' => $period->id],
            channels: ['log', 'sms', 'resend_email']
        );

        // New board → notify the configured board-role recipients (audience=board).
        // Resolve config ONCE and pass it to both boardRecipientsForEvent() and
        // notifyEvent() so the settings resolve a single time (arch W1).
        $resolvedNewBoard = app(NotificationSettingService::class)->resolve('new_board');
        if ($resolvedNewBoard['is_active']) {
            $this->notifyEvent(
                'new_board',
                $this->boardRecipientsForEvent('new_board', $resolvedNewBoard),
                [
                    'cluster_name' => tenant()?->name,
                    'period' => $period->name,
                ],
                'Nueva mesa directiva',
                'Se conformó una nueva mesa directiva: '.$period->name.'.',
                [
                    'category' => NotificationCategory::SYSTEM,
                    'click_action' => '/board-period',
                    'board_period_id' => $period->id,
                ],
                $resolvedNewBoard,
            );
        }

        // Auto-create a board announcement after commit (idempotent enough for v1).
        DB::afterCommit(function () use ($period): void {
            try {
                $this->createBoardAnnouncement($period);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Create + publish a board announcement for the new period via AnnouncementService.
     */
    private function createBoardAnnouncement(BoardPeriod $period): void
    {
        $type = AnnouncementType::findByKey(AnnouncementType::KEY_GENERAL);
        $priority = AnnouncementPriority::find(AnnouncementPriority::IMPORTANT)
            ?? AnnouncementPriority::find(AnnouncementPriority::NORMAL);
        $draftStatus = AnnouncementStatus::find(AnnouncementStatus::DRAFT);

        if (! $type || ! $priority || ! $draftStatus) {
            return;
        }

        $title = 'Nueva mesa directiva '.$this->periodYearsLabel($period);

        $announcement = Announcement::create([
            'title' => $title,
            'announcement_type_id' => $type->id,
            'announcement_priority_id' => $priority->id,
            'announcement_status_id' => $draftStatus->id,
            'content' => 'Se conformó la nueva mesa directiva: '.$period->name.'.',
            'target_type' => 'all',
            'target_selector' => null,
            'channel_push' => true,
            'channel_board' => true,
            'channel_email' => false,
            'channel_sms' => false,
        ]);

        app(AnnouncementService::class)->publish($announcement);
    }

    /**
     * Build a "YYYY-YYYY" (or "YYYY") label from the period's start/end dates,
     * falling back to the period name when dates are unavailable.
     */
    private function periodYearsLabel(BoardPeriod $period): string
    {
        $start = $period->start_date ? Carbon::parse($period->start_date)->year : null;
        $end = $period->end_date ? Carbon::parse($period->end_date)->year : null;

        if ($start && $end) {
            return $start === $end ? (string) $start : "{$start}-{$end}";
        }

        if ($start) {
            return (string) $start;
        }

        return $period->name;
    }

    /**
     * Close a board period and revoke all member Spatie roles.
     *
     * On early closure (the period's end_date is still in the future) the end_date
     * is truncated to $closeDate (default: today), clamped to start_date, so a
     * closed mandate never keeps a future end_date — which would misreport history
     * and block new periods (§7.3.2 wireframe: "cerrar periodo con fecha ...").
     */
    public function closePeriod(BoardPeriod $period, ?string $closeDate = null): BoardPeriod
    {
        return DB::transaction(function () use ($period, $closeDate): BoardPeriod {
            $closedStatus = BoardPeriodStatus::findOrFail(BoardPeriodStatus::CLOSED);

            // Load members with their roles before closing.
            $period->load('members.board_role');

            $outgoingUserIds = $period->members->pluck('user_id')->unique()->values()->all();

            foreach ($period->members as $member) {
                $this->revokeSpatieRole($member, $period->id);
            }

            $attributes = ['board_period_status_id' => $closedStatus->id];

            $effectiveClose = Carbon::parse($closeDate ?? Carbon::today()->toDateString());
            if ($period->end_date && $effectiveClose->lt($period->end_date)) {
                $attributes['end_date'] = $effectiveClose->lt($period->start_date)
                    ? Carbon::parse($period->start_date)->toDateString()
                    : $effectiveClose->toDateString();
            }

            $period->update($attributes);

            // §7.7: email the outgoing board that the period closed (after commit).
            $this->notifyUsers(
                $outgoingUserIds,
                'Periodo de mesa directiva cerrado',
                'El periodo de la mesa directiva "'.$period->name.'" ha sido cerrado. Gracias por tu servicio.',
                ['click_action' => '/board-period', 'board_period_id' => $period->id],
                channels: ['log', 'resend_email']
            );

            return $period->refresh();
        });
    }

    /**
     * Assert the period's members include at least one President, one Treasurer and one
     * Secretary (spec §7.3.2). Throws 422-mapped InvalidArgumentException when missing.
     *
     * @param  array<int, array{user_id: string, board_role_id: string, unit_id?: string|null}>  $membersData
     *
     * @throws \InvalidArgumentException
     */
    private function assertMandatoryRolesPresent(array $membersData): void
    {
        $roleIds = array_column($membersData, 'board_role_id');

        $required = [
            BoardRole::PRESIDENT => 'Presidente',
            BoardRole::TREASURER => 'Tesorero',
            BoardRole::SECRETARY => 'Secretario',
        ];

        $missing = [];
        foreach ($required as $roleId => $label) {
            if (! in_array($roleId, $roleIds, true)) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Faltan cargos obligatorios: '.implode(', ', $missing).'.'
            );
        }
    }

    /**
     * Assert the new period's [start_date, end_date] does NOT overlap a CURRENT
     * period (§7.3.2: "las fechas no pueden traslaparse con otro periodo VIGENTE").
     * Closed periods never block. Two ranges overlap when start <= other.end AND
     * end >= other.start.
     *
     * @throws \InvalidArgumentException
     */
    private function assertNoOverlappingPeriod(string $startDate, string $endDate): void
    {
        $overlaps = BoardPeriod::where('board_period_status_id', BoardPeriodStatus::CURRENT)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->exists();

        if ($overlaps) {
            throw new \InvalidArgumentException(
                'El rango de fechas se traslapa con un periodo vigente.'
            );
        }
    }

    /**
     * Assign the mapped global Spatie role to the central User.
     *
     * Public so direct board_member CRUD (BoardMemberController) keeps roles in
     * sync with seats, exactly like the start/close period flows (§7.3.2).
     *
     * @throws \InvalidArgumentException When the seat points to a nonexistent user.
     */
    public function grantSpatieRole(BoardMember $member): void
    {
        $member->load('board_role');
        $slug = $member->board_role?->spatie_role_slug;
        if (! $slug) {
            return;
        }

        $user = User::withoutGlobalScopes()->find($member->user_id);
        if (! $user) {
            throw new \InvalidArgumentException('El usuario asignado al cargo no existe.');
        }

        $appRole = AppRole::where('name', $slug)->where('guard_name', 'api')->first();
        if ($appRole) {
            $user->assignRole($appRole);
        }
    }

    /**
     * Revoke the mapped global Spatie role from the central User.
     *
     * The role is kept when ANOTHER current-period seat still grants the same
     * slug to the same user (e.g. duplicated vocal seats). $ignorePeriodId lets
     * closePeriod exclude the period being closed from that check.
     */
    public function revokeSpatieRole(BoardMember $member, ?string $ignorePeriodId = null): void
    {
        $member->load('board_role');
        $slug = $member->board_role?->spatie_role_slug;
        if (! $slug) {
            return;
        }

        $stillGranted = BoardMember::query()
            ->whereKeyNot($member->getKey())
            ->where('user_id', $member->user_id)
            ->when($ignorePeriodId !== null, fn ($query) => $query->where('board_period_id', '!=', $ignorePeriodId))
            ->whereHas('board_role', fn ($query) => $query->where('spatie_role_slug', $slug))
            ->whereHas('board_period', fn ($query) => $query->where('board_period_status_id', BoardPeriodStatus::CURRENT))
            ->exists();

        if ($stillGranted) {
            return;
        }

        $user = User::withoutGlobalScopes()->find($member->user_id);
        if (! $user) {
            return;
        }

        $appRole = AppRole::where('name', $slug)->where('guard_name', 'api')->first();
        if ($appRole && $user->hasRole($appRole)) {
            $user->removeRole($appRole);
        }
    }
}
