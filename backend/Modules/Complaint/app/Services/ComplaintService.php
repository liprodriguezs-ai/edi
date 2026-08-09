<?php

namespace Modules\Complaint\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Services\ChatService;
use Modules\Complaint\Models\Complaint;
use Modules\Complaint\Models\ComplaintSequence;
use Modules\Complaint\Models\ComplaintStatus;
use Modules\Complaint\Support\ComplaintAccess;
use Modules\Setting\Models\Setting;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * NOTE: NotifiesResidents::notifyEvent() already wraps its dispatch in
 * DB::afterCommit() internally (see notifyUsers()) — every notifyEvent() call
 * below runs safely from inside a DB::transaction() closure without needing
 * (or risking double-wrapping with) an explicit afterCommit() here.
 */
class ComplaintService
{
    use NotifiesResidents;

    /** Fallback defaults mirrored from the module-config manifest (design §10), used
     *  when no per-tenant Setting row exists yet (e.g. before module-config seeds it). */
    private const DEFAULT_REPORTER_ROLE_KEYS = ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'];

    private const DEFAULT_AUTO_NOTIFY_MANAGERS_ON_CREATE = true;

    private const DEFAULT_REQUIRES_EVIDENCE = false;

    private const DEFAULT_POST_TO_REPORTS_CHANNEL = true;

    /**
     * Per-instance memo for Setting::getValue lookups so a single request never
     * re-queries the same setting twice. The service is resolved fresh per
     * request (never a singleton), so the cache lifetime is the request.
     *
     * @var array<string, mixed>
     */
    private array $settingCache = [];

    private function getSetting(string $slug): mixed
    {
        return $this->settingCache[$slug] ??= Setting::getValue($slug);
    }

    /**
     * @return array<int, string>
     */
    private function reporterRoleKeys(): array
    {
        $configured = $this->getSetting('complaint.reporter_role_keys');

        return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_REPORTER_ROLE_KEYS;
    }

    private function autoNotifyManagersOnCreate(): bool
    {
        $configured = $this->getSetting('complaint.auto_notify_managers_on_create');

        return $configured === null ? self::DEFAULT_AUTO_NOTIFY_MANAGERS_ON_CREATE : (bool) $configured;
    }

    private function requiresEvidence(): bool
    {
        $configured = $this->getSetting('complaint.requires_evidence');

        return $configured === null ? self::DEFAULT_REQUIRES_EVIDENCE : (bool) $configured;
    }

    private function postToReportsChannelEnabled(): bool
    {
        $configured = $this->getSetting('complaint.post_to_reports_channel');

        return $configured === null ? self::DEFAULT_POST_TO_REPORTS_CHANNEL : (bool) $configured;
    }

    /**
     * Registration eligibility (spec "Registration Gate"): true when the given
     * user holds a role listed in complaint.reporter_role_keys. Mirrors the
     * EXACT runtime gate register() enforces (both read reporterRoleKeys(), so
     * they can never drift) — lets the frontend hide "Registrar incidencia" for
     * a user who would be rejected on submit despite holding the coarse
     * create.complaint Spatie permission (UX fix, spec Requirement 1).
     */
    public function canRegister(User $user): bool
    {
        return $user->hasAnyRole($this->reporterRoleKeys());
    }

    /**
     * Register a new complaint (spec "Registration Gate" + "Case Number Generation").
     *
     * Pre-flight (before the transaction): the actor must hold a role listed in
     * complaint.reporter_role_keys (runtime config gate, spec Requirement 1 — on
     * top of the coarse create.complaint Spatie permission enforced by the route)
     * and, when complaint.requires_evidence is on, must attach at least one file.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws HttpException
     */
    public function register(array $data, User $reporter): Complaint
    {
        // The per-tenant/year case_number counter (complaint_sequence) is a
        // tenant-scoped table — without an initialized tenant the row-lock
        // query below would run outside TenantScope, corrupting the shared
        // counter (mirrors NotifiesResidents' initialized-tenant guard).
        abort_unless(tenancy()->initialized, 500, 'No hay un tenant inicializado');

        if (! $reporter->hasAnyRole($this->reporterRoleKeys())) {
            abort(403, 'No tienes un rol autorizado para registrar incidencias');
        }

        if ($this->requiresEvidence() && ! isset($data['evidence'])) {
            abort(422, 'Se requiere adjuntar evidencia para registrar la incidencia');
        }

        $complaint = DB::transaction(function () use ($data, $reporter): Complaint {
            $complaint = new Complaint(Arr::except($data, ['evidence']));
            $complaint->reporter_user_id = $reporter->id;
            $complaint->complaint_status_id = ComplaintStatus::OPEN;
            $complaint->case_number = $this->nextCaseNumber((int) now()->year);
            $complaint->save();

            // NOTE (accepted tradeoff, mirrors PackageService::register): media is
            // attached inside the transaction. A later rollback strands an
            // already-written file with no DB row, compensated by the scheduled
            // media-library:clean job.
            if (isset($data['evidence']) && $data['evidence'] instanceof UploadedFile) {
                $complaint->addMedia($data['evidence'])->toMediaCollection('evidence');
            }

            if ($this->autoNotifyManagersOnCreate()) {
                $recipients = $this->resolveManagerRecipients($complaint);

                if ($recipients !== []) {
                    $this->notifyEvent(
                        'complaint_created',
                        $recipients,
                        ['case_number' => $complaint->case_number, 'title' => $complaint->title],
                        'Nueva incidencia registrada',
                        "Se registró la incidencia {$complaint->case_number}: {$complaint->title}",
                        ['click_action' => '/complaint/'.$complaint->id],
                    );
                }
            }

            return $complaint;
        });

        // Runs AFTER the transaction above has committed (DB::transaction only
        // returns once committed), mirroring AnnouncementService::publish's
        // post-save call to postToOfficialChatChannel — a chat-post failure must
        // never roll back or block the already-persisted complaint.
        $this->postReportsChannelNotice($complaint);

        return $complaint;
    }

    /**
     * Cross-post a MINIMAL notice to the tenant's "Reportes y sugerencias"
     * chat channel when a complaint is registered (user decision): case_number
     * + category name + a clickable deep link to the complaint detail — no
     * title, no unit, no denounced info. The channel is open to everyone
     * (read=all/write=all), so the notice is deliberately minimal; a board
     * member excluded from this complaint (§6 unit-linkage) or any other
     * user opening the link is still gated by the complaint detail route
     * itself (404/"no encontrada") — the notice link is identical for
     * everyone.
     *
     * Sends both body_html (rendered, sanitized server-side, DOMPurify'd
     * client-side) with the case_number wrapped in an <a href> and a plain
     * body fallback — mirrors AnnouncementService::postToOfficialChatChannel's
     * body/body_html pair.
     *
     * Gated by complaint.post_to_reports_channel (default true).
     * Best-effort: ANY failure (missing channel, chat write error) is logged
     * and swallowed so it can never break complaint registration.
     */
    private function postReportsChannelNotice(Complaint $complaint): void
    {
        if (! $this->postToReportsChannelEnabled()) {
            return;
        }

        try {
            $chatService = app(ChatService::class);
            $channel = $chatService->findReportsChannel();

            if ($channel === null) {
                Log::warning('Failed to post complaint notice to Reportes y sugerencias chat channel', [
                    'complaint_id' => $complaint->id,
                    'reason' => 'Reportes y sugerencias channel not found for tenant',
                ]);

                return;
            }

            $categoryName = $complaint->category?->name ?? 'Sin categoría';
            $deepLink = rtrim((string) config('app.frontend_url'), '/').'/complaint/'.$complaint->id;

            $body = "📋 Se registró la incidencia {$complaint->case_number} · {$categoryName}. Consúltala en el módulo Incidencias: {$deepLink}";
            $bodyHtml = '<p>📋 Se registró la incidencia <a href="'.$deepLink.'">'.e($complaint->case_number).'</a> · '.e($categoryName).'.</p>';

            $chatService->postSystemMessage($channel, $complaint->reporter_user_id, $body, $bodyHtml);
        } catch (\Throwable $e) {
            Log::warning('Failed to post complaint notice to Reportes y sugerencias chat channel', [
                'complaint_id' => $complaint->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Transition a complaint's status (spec "Status FSM").
     *
     * Only a configured manager (complaint.manager_role_keys) who is NOT excluded
     * for this complaint (§6 unit-linkage) may transition it. resolved_at is
     * stamped when entering a terminal state and cleared on reopen.
     *
     * @throws HttpException
     */
    public function changeStatus(Complaint $complaint, string $toStatusId, User $actor): Complaint
    {
        $this->assertManagerNotExcluded($complaint, $actor);

        if (! $this->isValidTransition($complaint->complaint_status_id, $toStatusId)) {
            abort(422, 'Transición de estado no permitida');
        }

        return DB::transaction(function () use ($complaint, $toStatusId): Complaint {
            $complaint->complaint_status_id = $toStatusId;
            $complaint->resolved_at = in_array($toStatusId, ComplaintStatus::TERMINAL, true) ? now() : null;
            $complaint->save();

            $recipients = array_values(array_unique(array_filter([
                $complaint->reporter_user_id,
                $complaint->assigned_user_id,
            ])));

            if ($recipients !== []) {
                $this->notifyEvent(
                    'complaint_status_changed',
                    $recipients,
                    ['case_number' => $complaint->case_number, 'title' => $complaint->title],
                    'Cambió el estado de una incidencia',
                    "La incidencia {$complaint->case_number} cambió de estado.",
                    ['click_action' => '/complaint/'.$complaint->id],
                );
            }

            return $complaint;
        });
    }

    /**
     * Assign a complaint to a responsible owner (spec "Assignment").
     *
     * The assignee must be a configured manager AND not excluded for this
     * complaint — an excluded (denounced-unit) user can never be assigned.
     *
     * @throws HttpException
     */
    public function assign(Complaint $complaint, string $assigneeUserId, User $actor): Complaint
    {
        $this->assertManagerNotExcluded($complaint, $actor);

        $assignee = User::find($assigneeUserId);

        if (! $assignee || ! $assignee->hasAnyRole(ComplaintAccess::managerRoleKeys())) {
            abort(422, 'El usuario asignado debe tener un rol gestor de incidencias');
        }

        if (in_array($assigneeUserId, ComplaintAccess::excludedUserIds($complaint->against_unit_id), true)) {
            abort(422, 'No se puede asignar a un usuario excluido de esta incidencia');
        }

        return DB::transaction(function () use ($complaint, $assigneeUserId): Complaint {
            $complaint->assigned_user_id = $assigneeUserId;
            $complaint->save();

            $this->notifyEvent(
                'complaint_assigned',
                [$assigneeUserId],
                ['case_number' => $complaint->case_number, 'title' => $complaint->title],
                'Se te asignó una incidencia',
                "Se te asignó la incidencia {$complaint->case_number}: {$complaint->title}",
                ['click_action' => '/complaint/'.$complaint->id],
            );

            return $complaint;
        });
    }

    /**
     * Eligible assignees for a complaint (spec "Assignment"): configured
     * managers (complaint.manager_role_keys) MINUS the unit-linkage exclusion
     * set (§6). Feeds the frontend assignment picker so it never lists a user
     * who would immediately be rejected by assign().
     *
     * @return Collection<int, User>
     */
    public function eligibleAssignees(Complaint $complaint): Collection
    {
        $eligibleIds = $this->resolveManagerRecipients($complaint);

        if ($eligibleIds === []) {
            return collect();
        }

        return User::whereIn('id', $eligibleIds)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Assert the actor is a configured manager (complaint.manager_role_keys) and
     * is not excluded from this complaint (§6 unit-linkage exclusion). Shared by
     * changeStatus and assign — both are manager-only, exclusion-respecting actions.
     *
     * @throws HttpException
     */
    private function assertManagerNotExcluded(Complaint $complaint, User $actor): void
    {
        if (! $actor->hasAnyRole(ComplaintAccess::managerRoleKeys())) {
            abort(403, 'No tienes un rol gestor de incidencias');
        }

        if (in_array($actor->id, ComplaintAccess::excludedUserIds($complaint->against_unit_id), true)) {
            abort(403, 'No puedes gestionar una incidencia vinculada a tu propia unidad');
        }
    }

    /**
     * FSM transition matrix (design §5.1). Reopen from a terminal state to
     * open/in_review is allowed (user decision, spec Requirement 5 amendment);
     * resolved<->dismissed direct swap is NOT allowed (must reopen first).
     */
    private function isValidTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        $transitions = [
            ComplaintStatus::OPEN => [ComplaintStatus::IN_REVIEW, ComplaintStatus::RESOLVED, ComplaintStatus::DISMISSED],
            ComplaintStatus::IN_REVIEW => [ComplaintStatus::OPEN, ComplaintStatus::RESOLVED, ComplaintStatus::DISMISSED],
            ComplaintStatus::RESOLVED => [ComplaintStatus::OPEN, ComplaintStatus::IN_REVIEW],
            ComplaintStatus::DISMISSED => [ComplaintStatus::OPEN, ComplaintStatus::IN_REVIEW],
        ];

        return in_array($to, $transitions[$from] ?? [], true);
    }

    /**
     * Configured manager-role user ids (complaint.manager_role_keys) MINUS the
     * unit-linkage exclusion set (§6). Used for complaint_created recipients
     * and the eligible-assignees picker.
     *
     * @return array<int, string>
     */
    private function resolveManagerRecipients(Complaint $complaint): array
    {
        $managerIds = $this->userIdsWithRole(ComplaintAccess::managerRoleKeys());
        $excluded = ComplaintAccess::excludedUserIds($complaint->against_unit_id);

        return array_values(array_diff($managerIds, $excluded));
    }

    /**
     * Next NNNNNN for the tenant/year counter row, read-modify-write under a row
     * lock. MUST run inside the surrounding register() transaction. Mirrors
     * Collections' PaymentService::nextReceiptSequence.
     */
    private function nextCaseNumber(int $year): string
    {
        $row = ComplaintSequence::where('year', $year)->lockForUpdate()->first();

        if (! $row) {
            $this->createComplaintSequenceRow($year);
            $row = ComplaintSequence::where('year', $year)->lockForUpdate()->firstOrFail();
        }

        $next = ((int) $row->getAttributes()['last_number']) + 1;
        $row->last_number = $next;
        $row->save();

        return sprintf('D-%04d-%06d', $year, $next);
    }

    /**
     * Create the counter row for a year, seeding last_number from the highest
     * existing case_number (backfill safety net, though this is a brand-new module).
     *
     * The INSERT runs in a nested transaction (Postgres SAVEPOINT) so a
     * concurrent winner's unique violation aborts only the savepoint, not the
     * whole register() transaction; the caller then re-reads the winning row.
     */
    private function createComplaintSequenceRow(int $year): void
    {
        $maxExisting = Complaint::withTrashed()
            ->where('case_number', 'like', sprintf('D-%04d-%%', $year))
            ->max('case_number');

        $lastNumber = $maxExisting ? (int) substr((string) $maxExisting, -6) : 0;

        try {
            DB::transaction(function () use ($year, $lastNumber): void {
                ComplaintSequence::create([
                    'year' => $year,
                    'last_number' => $lastNumber,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the row first — the caller re-reads it.
        }
    }
}
