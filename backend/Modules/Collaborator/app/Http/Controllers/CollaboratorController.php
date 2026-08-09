<?php

namespace Modules\Collaborator\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Collaborator\Http\Requests\CollaboratorRequest;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Services\CollaboratorService;
use Modules\Collaborator\Services\EmployeeService;
use Modules\User\Services\UserInvitationService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class CollaboratorController extends Controller
{
    protected $model = Collaborator::class;

    protected $request = CollaboratorRequest::class;

    protected $keyForPermission = 'collaborator';

    public function includes(): array
    {
        return array_merge(Collaborator::DEFAULT_RELATIONS, [
            'employee_position',
            'pay_frequency',
            'employee_shift',
        ]);
    }

    public function searchableBy(): array
    {
        return [
            'name',
            'last_name',
            'second_last_name',
            'email',
            'phone',
            'birth_place',
            'gender',
            'beneficiary',
            'rfc',
        ];
    }

    public function sortableBy(): array
    {
        return array_values(array_unique([
            ...parent::sortableBy(),
            'id',
        ]));
    }

    protected function buildIndexFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);
        $this->applyCentralUserSearchToQuery($query, $request->get('search')['value'] ?? null);

        return $query;
    }

    public function filterableBy(): array
    {
        return [
            'user_id',
            'job_position_id',
            'area_id',
            'contract_type_id',
            'nationality_id',
            'marital_status_id',
            'work_shift_id',
            'employee_position_id',
            'pay_frequency_id',
            'employee_shift_id',
            'is_active',
        ];
    }

    /**
     * Terminate an employee (sets is_active=false, records termination date and reason).
     * §7.2: "Dar de baja personal" is Admin-only — gated by delete.collaborator.
     */
    public function terminate(string $id, HttpRequest $request, EmployeeService $employeeService): JsonResponse
    {
        $this->authorize('delete.collaborator', Collaborator::class);

        $employee = Collaborator::findOrFail($id);

        $validated = $request->validate([
            'termination_date' => ['required', 'date'],
            'termination_reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string'],
            'revoke_app_access' => ['sometimes', 'boolean'],
        ]);

        try {
            $updated = $employeeService->terminate(
                $employee,
                $validated['termination_date'],
                $validated['termination_reason'],
                $validated['details'] ?? null,
                $validated['revoke_app_access'] ?? true,
            );

            return response()->json(['message' => 'Empleado dado de baja correctamente.', 'data' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reactivate a terminated employee by creating a new employment record.
     * §7.2: "Dar de alta personal" is Admin-only — gated by create.collaborator.
     */
    public function reactivate(string $id, HttpRequest $request, EmployeeService $employeeService): JsonResponse
    {
        $this->authorize('create.collaborator', Collaborator::class);

        $employee = Collaborator::findOrFail($id);

        $validated = $request->validate([
            'employee_position_id' => ['nullable', 'uuid', 'exists:employee_position,id'],
            'pay_frequency_id' => ['nullable', 'uuid', 'exists:pay_frequency,id'],
            'employee_shift_id' => ['nullable', 'uuid', 'exists:employee_shift,id'],
            'hire_date' => ['nullable', 'date'],
            'base_salary' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $newEmployee = $employeeService->reactivate($employee, $validated);

            return response()->json(['message' => 'Empleado reactivado correctamente (nuevo registro creado).', 'data' => $newEmployee], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Delegates create to CollaboratorService while keeping Orion's store flow.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $created = app(CollaboratorService::class)->create($request->validated());
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * Delegates update to CollaboratorService while keeping Orion's update flow.
     */
    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        app(CollaboratorService::class)->update($request->validated(), $entity->getKey());
    }

    /**
     * POST /collaborator/{id}/media
     * Upload a private document to the collaborator's `documents` collection.
     * Gated behind update.collaborator (board/admin/treasurer permission).
     */
    public function uploadDocument(string $id, HttpRequest $request): JsonResponse
    {
        $this->authorize('update.collaborator', Collaborator::class);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $collaborator = Collaborator::findOrFail($id);

        $media = $collaborator->addMedia($request->file('file'))
            ->toMediaCollection('documents');

        return response()->json(['data' => $media], 201);
    }

    /**
     * GET /collaborator/{id}/media/{mediaId}
     * Download a private document from the collaborator's collection.
     * Gated behind read.collaborator: §7.5.2.4 makes documents VISIBLE to
     * administrator, president and treasurer — viewing must not require update.
     */
    public function downloadDocument(string $id, string $mediaId, HttpRequest $request): mixed
    {
        $this->authorize('read.collaborator', Collaborator::class);

        $collaborator = Collaborator::findOrFail($id);
        $media = $collaborator->media()->where('id', $mediaId)->firstOrFail();

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$media->file_name.'"',
        ]);
    }

    /**
     * Current authenticated user's collaborator profile.
     */
    public function me(): JsonResponse|JsonResource
    {
        $userId = auth()->id();
        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $collaborator = Collaborator::query()
            ->where('user_id', $userId)
            ->with(Collaborator::DEFAULT_RELATIONS)
            ->first();

        if (! $collaborator) {
            return response()->json(['message' => 'Collaborator not found for current user.'], 404);
        }

        return $this->entityResponse($collaborator);
    }

    /**
     * Validates an array of rows for import without persisting.
     * §7.2: importing staff is part of "Dar de alta personal" — Admin-only.
     */
    public function validateImport(HttpRequest $request): JsonResponse
    {
        $this->authorize('create.collaborator', Collaborator::class);

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'nullable|string',
            'rows.*.last_name' => 'nullable|string',
        ]);

        $service = app(CollaboratorService::class);
        $result = $service->validateImportRows($request->input('rows'));

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Batch import collaborators from validated rows.
     * Company data is sent separately from the "Empresa" sheet.
     */
    public function batchImport(HttpRequest $request): JsonResponse
    {
        $this->authorize('create.collaborator', Collaborator::class);

        $request->validate([
            'rows' => 'required|array|min:1',
            'company' => 'nullable|array',
        ]);

        $result = app(CollaboratorService::class)->batchImport(
            rows: $request->input('rows'),
            companyData: $request->input('company', []),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Resend invitation email for a collaborator (create_as_user true, user_id null).
     * Id as string to avoid route model binding before tenancy is initialized.
     */
    public function resendInvitation(string $id, UserInvitationService $invitationService): JsonResponse
    {
        $this->authorize('update.collaborator', Collaborator::class);

        $collaborator = Collaborator::findOrFail($id);

        if ($collaborator->user_id !== null) {
            return response()->json(['message' => __('El colaborador ya tiene cuenta de usuario.')], 400);
        }
        if (! (bool) ($collaborator->create_as_user ?? false)) {
            return response()->json(['message' => __('No hay invitación pendiente para este colaborador.')], 400);
        }

        try {
            $invitationService->resendInvitation($collaborator);

            return response()->json(['message' => __('Invitación reenviada correctamente.')], 200);
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
