<?php

namespace Modules\Complaint\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Complaint\Http\Requests\ComplaintAssignRequest;
use Modules\Complaint\Http\Requests\ComplaintMessageRequest;
use Modules\Complaint\Http\Requests\ComplaintTransitionRequest;
use Modules\Complaint\Models\Complaint;
use Modules\Complaint\Services\ComplaintHistoryService;
use Modules\Complaint\Services\ComplaintMessageService;
use Modules\Complaint\Services\ComplaintService;
use Modules\Setting\Models\Setting;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ComplaintController extends Controller
{
    protected $model = Complaint::class;

    protected $keyForPermission = 'complaint';

    public function includes(): array
    {
        return ['category', 'priority', 'status', 'reporter', 'assignee', 'against_unit', 'messages', 'media'];
    }

    public function filterableBy(): array
    {
        return ['complaint_status_id', 'complaint_category_id', 'complaint_priority_id', 'assigned_user_id'];
    }

    public function sortableBy(): array
    {
        return ['created_at', 'resolved_at'];
    }

    public function searchableBy(): array
    {
        return ['case_number', 'title', 'against_description'];
    }

    /**
     * Confidential exclusion (§6): hide complaints whose against_unit_id is a unit
     * the caller actively belongs to, unless the caller is the reporter.
     */
    protected function buildIndexFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        return $query->visibleTo($request->user());
    }

    protected function buildShowFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        return $query->visibleTo($request->user());
    }

    /**
     * Confidential exclusion (§6), continued: update/destroy/restore MUST apply
     * the same visibleTo() scope as index/show, or an excluded manager could
     * mutate a complaint they cannot even see by guessing its id.
     */
    protected function buildUpdateFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildUpdateFetchQuery($request, $requestedRelations);

        return $query->visibleTo($request->user());
    }

    protected function buildDestroyFetchQuery(Request $request, array $requestedRelations, bool $softDeletes): Builder
    {
        $query = parent::buildDestroyFetchQuery($request, $requestedRelations, $softDeletes);

        return $query->visibleTo($request->user());
    }

    protected function buildRestoreFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildRestoreFetchQuery($request, $requestedRelations);

        return $query->visibleTo($request->user());
    }

    /**
     * Delegate registration to ComplaintService::register (case_number, FSM seed,
     * exclusion-aware notify). Orion calls performStore then re-fetches the
     * entity, so we swap $entity's attributes with the persisted model.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $created = app(ComplaintService::class)->register(
            $request->validated(),
            $request->user()
        );

        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * Transition a complaint's status (spec "Status FSM").
     *
     * Complaint resolved by id INSIDE the method (not implicit route-model
     * binding) so the lookup runs after InitializeTenancyFromUser and stays
     * tenant-scoped (Package route-order + tenancy gotcha, verbatim).
     */
    public function transition(ComplaintTransitionRequest $request, string $complaintId): JsonResponse
    {
        $this->authorize('update.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        $updated = app(ComplaintService::class)->changeStatus(
            $complaint,
            $request->validated('complaint_status_id'),
            $request->user()
        );

        return response()->json(['data' => $updated]);
    }

    /**
     * Assign a complaint to a responsible manager (spec "Assignment").
     */
    public function assign(ComplaintAssignRequest $request, string $complaintId): JsonResponse
    {
        $this->authorize('update.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        $updated = app(ComplaintService::class)->assign(
            $complaint,
            $request->validated('assigned_user_id'),
            $request->user()
        );

        return response()->json(['data' => $updated]);
    }

    /**
     * Registration eligibility for the CURRENT user (spec "Registration
     * Gate"): mirrors the exact runtime gate ComplaintService::register
     * enforces (complaint.reporter_role_keys vs the caller's roles) so the
     * frontend can hide "Registrar incidencia" for a role that would be
     * rejected on submit — a coarse create.complaint Spatie pass alone does
     * NOT guarantee this (UX fix, review finding).
     */
    public function registrationEligibility(Request $request): JsonResponse
    {
        $this->authorize('read.complaint', Complaint::class);

        $canRegister = app(ComplaintService::class)->canRegister($request->user());

        return response()->json(['data' => ['can_register' => $canRegister]]);
    }

    /**
     * Eligible assignees for a complaint (spec "Assignment"): configured
     * managers minus the unit-linkage exclusion set (§6) — narrows the
     * frontend picker to users who can legally be assigned instead of
     * exposing every user in the tenant.
     */
    public function eligibleAssignees(Request $request, string $complaintId): JsonResponse
    {
        $this->authorize('read.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        $assignees = app(ComplaintService::class)->eligibleAssignees($complaint);

        return response()->json(['data' => $assignees]);
    }

    /**
     * Post a follow-up message to a complaint's thread (spec "Follow-up Message
     * Thread"). Gated by read.complaint at the route; ComplaintMessageService
     * additionally enforces the per-complaint visibility/exclusion rule.
     */
    public function message(ComplaintMessageRequest $request, string $complaintId): JsonResponse
    {
        $this->authorize('read.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        $message = app(ComplaintMessageService::class)->postMessage(
            $complaint,
            $request->validated('body'),
            $request->user(),
            $request->file('attachment')
        );

        return response()->json(['data' => $message], 201);
    }

    /**
     * Change-history timeline for a complaint (spec "History"), read from the
     * existing Laravel Auditing trail (owen-it, polymorphic via BaseModel) —
     * no new table. Same visibility gate as messages(): a manager excluded by
     * unit-linkage (§6) cannot read the history of a complaint they cannot
     * access, even if they can guess its id.
     */
    public function history(Request $request, string $complaintId): JsonResponse
    {
        $this->authorize('read.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        app(ComplaintMessageService::class)->assertVisible($complaint, $request->user());

        $history = app(ComplaintHistoryService::class)->history($complaint);

        return response()->json(['data' => $history]);
    }

    /**
     * List a complaint's message thread, chronologically ordered.
     */
    public function messages(Request $request, string $complaintId): JsonResponse
    {
        $this->authorize('read.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        app(ComplaintMessageService::class)->assertVisible($complaint, $request->user());

        // Cap the thread to the latest 100 messages: fetch newest-first under
        // the limit, then re-sort ascending in PHP so the payload stays
        // chronological for the UI (unbounded ->get() was review finding W3).
        $messages = $complaint->messages()
            ->with(['author', 'media'])
            ->reorder('created_at', 'desc')
            ->limit(100)
            ->get()
            ->sortBy('created_at')
            ->values();

        return response()->json(['data' => $messages]);
    }

    /**
     * Append the tenant's resolution_days_target (module-config, memoized per
     * request) to the detail payload so ComplaintDetailPage can drive the
     * aging chip thresholds without a second round trip.
     */
    protected function afterShow(Request $request, Model $entity): null
    {
        $entity->setAttribute('resolution_days_target', $this->resolutionDaysTarget());

        return null;
    }

    private ?int $resolutionDaysTargetCache = null;

    private function resolutionDaysTarget(): int
    {
        return $this->resolutionDaysTargetCache ??= (int) (Setting::getValue('complaint.resolution_days_target') ?? 15);
    }
}
