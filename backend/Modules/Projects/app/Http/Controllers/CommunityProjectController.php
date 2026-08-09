<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Handlers\ContributionEarmarkHandler;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Services\CommunityProjectService;
use Modules\Projects\Services\ContributionService;
use Modules\Projects\Services\ProjectActivationService;
use Modules\Projects\Services\ProjectReminderService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Support\TenantMoney;

class CommunityProjectController extends Controller
{
    protected $model = CommunityProject::class;

    protected $keyForPermission = 'community_project';

    public function includes(): array
    {
        return [
            'status',
            'category',
            'participationType',
            'amount_mode',
            'responsibleUser',
            'media',
        ];
    }

    public function filterableBy(): array
    {
        return [
            'community_project_status_id',
            'participation_type_id',
            'community_project_category_id',
            'visible_to_residents',
        ];
    }

    public function sortableBy(): array
    {
        return ['title', 'start_date', 'due_date', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['title', 'description'];
    }

    /**
     * §11.8.7 (finding projects-05): residents only see VISIBLE, non-draft projects.
     * Board/admin (update.community_project) see everything.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeVisibleProjects(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeVisibleProjects(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    /**
     * FSM (finding projects-10): a project is always created as DRAFT — status
     * changes only happen through /activate, /close and /cancel.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $attributes['community_project_status_id'] = CommunityProjectStatus::DRAFT;

        $entity->fill($attributes);

        // Rich text (dual field): description_html is NOT mass-assignable — sanitize it
        // server-side and derive the plain-text description. Pass $request->all(): Orion
        // strips non-fillable keys from $attributes (mirrors AnnouncementController).
        app(CommunityProjectService::class)->applyRichTextContent($entity, $request->all());

        $entity->save();
    }

    /**
     * FSM + §11.2 matrix nuance (findings projects-10 / projects-12): the status is
     * never PATCHable, and writers WITHOUT cancel.community_project (treasurer,
     * secretary, vocal) may only edit projects they created and only while no money
     * has been contributed.
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        unset($attributes['community_project_status_id']);

        $this->assertCanEditProject($entity);

        $entity->fill($attributes);

        // Rich text (dual field): see performStore — $request->all() carries the
        // non-fillable description_html that Orion filtered out of $attributes.
        app(CommunityProjectService::class)->applyRichTextContent($entity, $request->all());

        $entity->save();
    }

    /**
     * POST /community-project/{id}/activate
     * Transition project from draft → active.
     */
    public function activate(int|string $id): JsonResponse
    {
        $this->authorize('update.community_project', CommunityProject::class);

        $project = CommunityProject::findOrFail($id);

        try {
            $activated = app(ProjectActivationService::class)->activate($project);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $activated->load('status')]);
    }

    /**
     * POST /community-project/{id}/close
     * Transition project to executed status.
     */
    public function close(int|string $id): JsonResponse
    {
        $this->authorize('update.community_project', CommunityProject::class);

        $project = CommunityProject::findOrFail($id);

        try {
            $executed = app(ProjectActivationService::class)->execute($project);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $executed->load('status')]);
    }

    /**
     * POST /community-project/{id}/cancel
     * §11.2: cancel is President/Administrator only (cancel.community_project),
     * never reachable through PATCH (finding projects-12).
     */
    public function cancel(int|string $id): JsonResponse
    {
        $this->authorize('cancel.community_project', CommunityProject::class);

        $project = CommunityProject::findOrFail($id);

        try {
            $cancelled = app(ProjectActivationService::class)->cancel($project);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $cancelled->load('status')]);
    }

    /**
     * POST /community-project/{id}/contribute — BOARD-ONLY manual adjustment of a
     * contribution's contributed_amount (no money movement).
     *
     * Contribution MONEY no longer enters here (earmark consolidation): it travels
     * as an earmarked payment — payment/register for board direct capture,
     * payment/submit for resident reports — with earmark_type=project_contribution,
     * so residents ALWAYS pass through treasurer validation. The retired payment
     * fields are rejected outright (422) so stale clients fail loudly.
     */
    public function contribute(int|string $id, HttpRequest $request): JsonResponse
    {
        $this->authorize('read.community_project', CommunityProject::class);

        // Residents must not even learn that a hidden/draft project exists → 404.
        $project = $this->scopeVisibleProjects(CommunityProject::query())->findOrFail($id);

        // Board-only (§ user requirement "no puede ser directo"): authorization wins
        // before validation (same ordering as activate/close/cancel) so an unauthorized
        // caller gets 403 without learning the endpoint's field contract. Residents
        // report money via payment/submit + earmark and wait for validation.
        $user = auth()->guard('sanctum')->user();
        if (! $user || ! ($user->tokenCan('update.project_contribution') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $retiredFieldMessage = 'Este endpoint ya no acepta datos de pago. Registra el pago con destino etiquetado (earmark) a la aportación.';

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'unit_id' => ['nullable', 'uuid'],
            // Retired contract: this endpoint no longer moves money (earmark consolidation).
            'payment_method_id' => ['prohibited'],
            'bank_account_id' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'reference' => ['prohibited'],
        ], [
            'payment_method_id.prohibited' => $retiredFieldMessage,
            'bank_account_id.prohibited' => $retiredFieldMessage,
            'paid_at.prohibited' => $retiredFieldMessage,
            'reference.prohibited' => $retiredFieldMessage,
        ]);

        if ($project->participation_type_id === ParticipationType::MANDATORY) {
            return response()->json(['message' => 'Las aportaciones de un proyecto obligatorio se pagan a través de su cargo.'], 422);
        }

        $contributableStatuses = [CommunityProjectStatus::ACTIVE, CommunityProjectStatus::FULLY_FUNDED];
        if (! in_array($project->community_project_status_id, $contributableStatuses, true)) {
            return response()->json(['message' => 'El proyecto no está activo. No se aceptan aportaciones.'], 422);
        }

        $contribution = $this->resolveContribution($project, $validated['unit_id'] ?? null);

        if ($contribution === null) {
            return response()->json(['message' => 'No existe una aportación de tu unidad para este proyecto.'], 422);
        }

        try {
            $updated = app(ContributionService::class)->contribute($contribution, (int) $validated['amount']);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Aportación registrada exitosamente.',
            'data' => $updated->load('contributionStatus'),
        ]);
    }

    /**
     * GET /community-project/{id}/contribution-payments — earmarked payments reported
     * against this project's contributions (the money trail behind the summary).
     * Board (update.community_project) sees every payment; a non-board caller only
     * sees payments of their own active units. Optional ?status=pending narrows to
     * PENDING_VALIDATION (the review queue).
     */
    public function contributionPayments(int|string $id, HttpRequest $request): JsonResponse
    {
        $this->authorize('read.community_project', CommunityProject::class);

        $project = $this->scopeVisibleProjects(CommunityProject::query())->findOrFail($id);

        // Lean by design: the current consumer (project detail pending-pill) only reads
        // earmark_id, so no relations are eager-loaded and the set is bounded. When a
        // board money-trail UI lands, widen via request params sized to that UI.
        $query = Payment::query()
            ->where('earmark_type', ContributionEarmarkHandler::EARMARK_TYPE)
            ->whereIn('earmark_id', ProjectContribution::query()
                ->where('community_project_id', $project->id)
                ->select('id'))
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->limit(500);

        if (! $this->hasBoardProjectAccess()) {
            $ownUnitIds = UnitUser::query()
                ->where('user_id', auth()->guard('sanctum')->id())
                ->whereNull('end_date')
                ->pluck('unit_id');

            $query->whereIn('unit_id', $ownUnitIds);
        }

        if ($request->query('status') === 'pending') {
            $query->where('payment_status_id', PaymentStatus::PENDING_VALIDATION);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * GET /community-project/{id}/summary
     * Return rollup: raised, executed, available (Money objects — available may
     * be negative when the project has over-executed against what it raised).
     */
    public function summary(int|string $id): JsonResponse
    {
        $this->authorize('read.community_project', CommunityProject::class);

        $project = $this->scopeVisibleProjects(CommunityProject::query())->findOrFail($id);

        $summary = app(ContributionService::class)->getSummary($project);

        return response()->json(['data' => [
            'raised' => TenantMoney::make($summary['raised']),
            'executed' => TenantMoney::make($summary['executed']),
            'available' => TenantMoney::make($summary['available']),
        ]]);
    }

    /**
     * POST /community-project/{id}/media
     * Upload a document to a project (up to 5, max 10 MB each). Documents are not
     * money, so the stricter assertCanEditProject gate is intentionally NOT applied.
     */
    public function uploadMedia(string $id, HttpRequest $request): JsonResponse
    {
        $this->authorize('update.community_project', CommunityProject::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
        ]);

        $project = CommunityProject::findOrFail($id);

        try {
            $media = app(CommunityProjectService::class)->attachDocument($project, $request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $media], 201);
    }

    /**
     * DELETE /community-project/{id}/media/{mediaId}
     * Remove a document from a project. The media must belong to this project's
     * documents collection (404 otherwise).
     */
    public function deleteMedia(string $id, string $mediaId): JsonResponse
    {
        $this->authorize('update.community_project', CommunityProject::class);

        $project = CommunityProject::findOrFail($id);

        app(CommunityProjectService::class)->detachDocument($project, $mediaId);

        return response()->json(['data' => true]);
    }

    /**
     * POST /community-project/{id}/send-contribution-reminder
     * Manually notify the units with a pending contribution. Voluntary + ACTIVE or
     * FULLY_FUNDED projects only; returns the number of units reminded (200 even at 0).
     */
    public function sendContributionReminder(string $id): JsonResponse
    {
        $this->authorize('update.community_project', CommunityProject::class);

        $project = CommunityProject::findOrFail($id);

        if ($project->participation_type_id !== ParticipationType::VOLUNTARY) {
            return response()->json(['message' => 'Solo proyectos voluntarios activos aceptan recordatorios.'], 422);
        }

        $remindableStatuses = [CommunityProjectStatus::ACTIVE, CommunityProjectStatus::FULLY_FUNDED];
        if (! in_array($project->community_project_status_id, $remindableStatuses, true)) {
            return response()->json(['message' => 'Solo proyectos voluntarios activos aceptan recordatorios.'], 422);
        }

        $sent = app(ProjectReminderService::class)->sendPendingReminders($project);

        return response()->json([
            'data' => ['sent' => $sent],
            'message' => "Recordatorio enviado a {$sent} unidad(es).",
        ]);
    }

    /**
     * Restrict a query to what the current user may see (§11.8.7).
     */
    protected function scopeVisibleProjects(Builder $query): Builder
    {
        if ($this->hasBoardProjectAccess()) {
            return $query;
        }

        return $query
            ->where('visible_to_residents', true)
            ->where('community_project_status_id', '!=', CommunityProjectStatus::DRAFT);
    }

    /**
     * Board/admin proxy: anyone allowed to update projects sees and manages all.
     */
    private function hasBoardProjectAccess(): bool
    {
        $user = auth()->guard('sanctum')->user();

        return $user !== null && ($user->tokenCan('update.community_project') || $user->tokenCan('*'));
    }

    /**
     * Find the contribution the caller is paying into: an explicit unit, or the
     * single unit the user belongs to. Null when none (or ambiguous without unit_id).
     */
    private function resolveContribution(CommunityProject $project, ?string $unitId): ?ProjectContribution
    {
        $query = ProjectContribution::query()->where('community_project_id', $project->id);

        if ($unitId !== null) {
            return $query->where('unit_id', $unitId)->first();
        }

        $userUnitIds = UnitUser::query()
            ->where('user_id', auth()->guard('sanctum')->id())
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->unique()
            ->values();

        $matches = $query->whereIn('unit_id', $userUnitIds)->limit(2)->get();

        // Ambiguous (user belongs to several targeted units) → require unit_id.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * §11.2 ⚠️: writers without cancel.community_project may only edit their own
     * projects, and only before any money has been contributed.
     *
     * @throws AuthorizationException
     */
    private function assertCanEditProject(Model $project): void
    {
        $user = auth()->guard('sanctum')->user();

        if ($user && ($user->tokenCan('cancel.community_project') || $user->tokenCan('*'))) {
            return;
        }

        if (! $user || $project->user_created_id !== $user->id) {
            throw new AuthorizationException('Solo puedes editar proyectos creados por ti.');
        }

        $hasMoney = ProjectContribution::query()
            ->where('community_project_id', $project->id)
            ->where('contributed_amount', '>', 0)
            ->exists();

        if ($hasMoney) {
            throw new AuthorizationException('No se puede editar un proyecto que ya tiene aportaciones.');
        }
    }
}
