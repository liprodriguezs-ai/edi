<?php

namespace Modules\Stimulation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Stimulation\Http\Requests\AttendanceUpsertRequest;
use Modules\Stimulation\Models\StimulationAttendance;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Services\StimulationAttendanceService;
use QCH\Core\Http\Controllers\Controller;

/**
 * D10 — stimulation_attendance authorizes under the SAME permission as
 * `stimulation_group`; reads go through Orion (index/search/show only, no
 * destroy — attendance rows are facts, REQ-SA-002), writes go through the
 * custom bulk-upsert endpoint (REQ-SA-001).
 */
class StimulationAttendanceController extends Controller
{
    protected $model = StimulationAttendance::class;

    protected $keyForPermission = 'stimulation_group';

    public function includes(): array
    {
        return ['stimulation_group', 'child', 'attendance_payment_status'];
    }

    public function sortableBy(): array
    {
        return ['session_date', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['stimulation_group_id', 'child_id', 'session_date'];
    }

    /**
     * POST /api/stimulation_group/{stimulation_group}/attendance — REQ-SA-001.
     * Implicit route-model binding: the TenantScope global scope makes a
     * cross-tenant group id resolve to 404, never 403 or data (REQ-XC-004).
     */
    public function upsert(
        StimulationGroup $stimulation_group,
        AttendanceUpsertRequest $request,
        StimulationAttendanceService $service
    ): JsonResponse {
        $upserted = $service->upsert($stimulation_group, $request->validated());

        return response()->json(['data' => $upserted->values()]);
    }
}
