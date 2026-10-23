<?php

namespace Modules\Appointment\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use Modules\Appointment\Http\Requests\AppointmentCancelRequest;
use Modules\Appointment\Http\Requests\AppointmentCreateRequest;
use Modules\Appointment\Http\Requests\AppointmentRescheduleRequest;
use Modules\Appointment\Http\Requests\AppointmentUpdateRequest;
use Modules\Appointment\Http\Requests\AppointmentWalkInRequest;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Services\AppointmentLifecycleService;
use Modules\Appointment\Services\AppointmentSuggestionService;
use Modules\ChildRecord\Models\Child;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * Orion serves search/index/show/store/update; there is no destroy -- an
 * appointment is retired by cancel. Every transition is an explicit route
 * delegating to AppointmentLifecycleService (odd/tasks/edi-agenda.md).
 */
class AppointmentController extends Controller
{
    protected $model = Appointment::class;

    protected $keyForPermission = 'appointment';

    public function includes(): array
    {
        return [
            'child', 'health_center', 'practitioner', 'practitioner.user',
            'appointment_type', 'appointment_status', 'visit', 'follow_up',
        ];
    }

    public function alwaysIncludes(): array
    {
        return ['child', 'practitioner', 'practitioner.user', 'appointment_type', 'appointment_status'];
    }

    public function filterableBy(): array
    {
        return [
            'child_id', 'health_center_id', 'practitioner_id', 'appointment_type_id',
            'appointment_status_id', 'starts_at', 'visit_id', 'follow_up_id', 'is_walk_in',
        ];
    }

    public function sortableBy(): array
    {
        return ['starts_at', 'arrived_at', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['child.first_name', 'child.last_name_paternal', 'child.last_name_maternal', 'child.curp'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(AppointmentCreateRequest::class)->validated();
        $created = app(AppointmentLifecycleService::class)->book($validated);

        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(AppointmentUpdateRequest::class)->validated();
        $updated = app(AppointmentLifecycleService::class)->updateNotes($entity, $validated);

        $entity->setRawAttributes($updated->getAttributes());
        $entity->syncOriginal();
    }

    /**
     * POST /api/appointment/walk_in — "Registrar sin cita".
     */
    public function walkIn(AppointmentWalkInRequest $request, AppointmentLifecycleService $service): JsonResponse
    {
        $created = $service->walkIn($request->validated())->load($this->includes());

        return response()->json(['data' => $created], 201);
    }

    public function confirm(Appointment $appointment, AppointmentLifecycleService $service): JsonResponse
    {
        return $this->respond($service->confirm($appointment));
    }

    public function arrive(Appointment $appointment, AppointmentLifecycleService $service): JsonResponse
    {
        return $this->respond($service->arrive($appointment));
    }

    /**
     * POST /api/appointment/{id}/start — "Iniciar atención"; the response
     * carries the new visit so the client can open the EDI capture.
     */
    public function start(Appointment $appointment, AppointmentLifecycleService $service): JsonResponse
    {
        return $this->respond($service->start($appointment));
    }

    public function reschedule(Appointment $appointment, AppointmentRescheduleRequest $request, AppointmentLifecycleService $service): JsonResponse
    {
        return $this->respond($service->reschedule($appointment, $request->validated()));
    }

    public function cancel(Appointment $appointment, AppointmentCancelRequest $request, AppointmentLifecycleService $service): JsonResponse
    {
        return $this->respond($service->cancel($appointment, $request->validated()['reason']));
    }

    public function noShow(Appointment $appointment, AppointmentLifecycleService $service): JsonResponse
    {
        return $this->respond($service->markNoShow($appointment));
    }

    /**
     * POST /api/appointment/mark_no_show — end-of-day close for a clinic day.
     */
    public function markDayNoShow(HttpRequest $request, AppointmentLifecycleService $service): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'health_center_id' => ['nullable', 'uuid', Rule::exists('health_center', 'id')->where('tenant_id', tenant('id'))],
        ]);

        $updated = $service->markDayNoShow($validated['date'], $validated['health_center_id'] ?? null);

        return response()->json([
            'message' => $updated === 1 ? 'Se marcó 1 cita como no asistió.' : "Se marcaron {$updated} citas como no asistió.",
            'data' => ['updated' => $updated],
        ]);
    }

    /**
     * GET /api/appointment/suggestion?child_id= — next appointment proposed from
     * the child's latest server-derived conduct (S7). Never books anything.
     */
    public function suggestion(HttpRequest $request, AppointmentSuggestionService $service): JsonResponse
    {
        $validated = $request->validate([
            'child_id' => ['required', 'uuid', Rule::exists('child', 'id')->where('tenant_id', tenant('id'))],
        ]);

        return response()->json(['data' => $service->suggestFor(Child::findOrFail($validated['child_id']))]);
    }

    private function respond(Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $appointment->load($this->includes())]);
    }
}
