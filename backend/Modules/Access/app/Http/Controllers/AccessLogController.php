<?php

namespace Modules\Access\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Access\Models\AccessLog;
use Modules\Access\Services\AccessService;
use Modules\Housing\Models\UnitUser;
use QCH\Core\Http\Controllers\Controller;

class AccessLogController extends Controller
{
    protected $model = AccessLog::class;

    protected $keyForPermission = 'access_log';

    public function includes(): array
    {
        return ['visitor', 'unit', 'access_entry_type', 'access_verification', 'guard_user', 'visitor_motive'];
    }

    public function filterableBy(): array
    {
        return ['unit_id', 'visitor_id', 'access_entry_type_id', 'guard_user_id', 'entered_at', 'exited_at', 'denied_at', 'visitor_motive_id', 'access_verification_id', 'plate'];
    }

    public function sortableBy(): array
    {
        return ['entered_at', 'exited_at', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['visitor_name', 'authorized_by', 'plate'];
    }

    /**
     * Scan a QR token and register entry. Blacklist matches are DENIED (§14.5 r7):
     * the attempt is recorded in the log and admin + security are alerted.
     */
    public function scanQr(Request $request): JsonResponse
    {
        $this->authorize('create.access_log', AccessLog::class);

        $request->validate(['token' => ['required', 'string']]);

        $result = app(AccessService::class)->scanQr(
            $request->input('token'),
            ['guard_user_id' => $request->user()?->id]
        );

        return response()->json($result, $result['valid'] ? 200 : 422);
    }

    /**
     * Register a manual entry without a prior QR (§14.4.4 — phone/chat verified).
     * Captures identification, plate, motive and an optional gate photo.
     */
    public function manualEntry(Request $request): JsonResponse
    {
        $this->authorize('create.access_log', AccessLog::class);

        $validated = $request->validate($this->manualCaptureRules([
            'visitor_name' => ['required', 'string', 'max:255'],
            'access_verification_id' => ['required', 'uuid', Rule::exists('access_verification', 'id')],
            'authorized_by' => ['nullable', 'string', 'max:255'],
        ]));

        $accessLog = app(AccessService::class)->manualEntry(array_merge(
            $validated,
            [
                'guard_user_id' => $request->user()?->id,
                'photo' => $request->file('photo'),
            ]
        ));

        if ($accessLog->denied_at !== null) {
            return response()->json([
                'message' => 'Visitante o vehículo en lista negra. Entrada denegada.',
                'data' => $accessLog,
            ], 422);
        }

        return response()->json(['data' => $accessLog->load(['unit', 'access_entry_type', 'access_verification', 'visitor_motive'])], 201);
    }

    /**
     * §14.4.4–14.4.5: gate registers a visit WITHOUT prior QR and pushes an URGENT
     * approval request to the unit's residents. The gate then polls the visitor
     * status (pending_approval → approved / rejected); the 60s timeout falls back
     * to manual-entry with phone verification.
     */
    public function entryRequest(Request $request): JsonResponse
    {
        $this->authorize('create.access_log', AccessLog::class);

        $validated = $request->validate($this->manualCaptureRules([
            'name' => ['required', 'string', 'max:255'],
            'visitor_motive_id' => ['required', 'uuid', Rule::exists('visitor_motive', 'id')->where('tenant_id', tenant('id'))],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]));

        $result = app(AccessService::class)->requestEntry(array_merge(
            $validated,
            [
                'guard_user_id' => $request->user()?->id,
                'photo' => $request->file('photo'),
            ]
        ));

        if ($result['visitor'] === null) {
            return response()->json([
                'message' => 'Visitante o vehículo en lista negra. Entrada denegada.',
                'data' => $result['denied_log'],
            ], 422);
        }

        return response()->json(['data' => $result['visitor']->load(['unit', 'visitor_motive', 'visitor_status'])], 201);
    }

    /**
     * §14.4.1 / §14.4.6: visitors currently inside (entered, no exit) for the gate.
     */
    public function now(Request $request): JsonResponse
    {
        $this->authorize('read.access_log', AccessLog::class);

        return response()->json(['data' => app(AccessService::class)->currentlyInside()]);
    }

    /**
     * §14.2 "Ver bitácora propia" / §14.3.3: the caller's own log — every access
     * registered for the units they actively belong to (including manual entries
     * that have no visitor row). Self-scoped: no broad read.access_log required.
     */
    public function myLog(Request $request): JsonResponse
    {
        $unitIds = UnitUser::where('user_id', $request->user()?->id)
            ->whereNull('end_date')
            ->pluck('unit_id');

        $logs = AccessLog::whereIn('unit_id', $unitIds)
            ->with(['visitor', 'unit', 'access_entry_type', 'access_verification', 'visitor_motive'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($logs);
    }

    /**
     * §14.2 (personal): the caller's own rotating entry QR. Self-scoped — any
     * authenticated resident/renter may fetch THEIR OWN QR; no read.access_log
     * required. seconds_remaining is computed SERVER-SIDE; rotation is lazy.
     */
    public function myQr(Request $request): JsonResponse
    {
        return response()->json([
            'data' => app(AccessService::class)->resolveMyQr($request->user()),
        ]);
    }

    /**
     * Mark exit for an existing access log entry.
     */
    public function checkout(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.access_log', AccessLog::class);

        $accessLog = AccessLog::findOrFail($id);

        if ($accessLog->denied_at !== null) {
            return response()->json(['message' => 'No se puede registrar salida de un acceso denegado.'], 422);
        }

        if ($accessLog->exited_at !== null) {
            return response()->json(['message' => 'La salida ya fue registrada.'], 422);
        }

        $updated = app(AccessService::class)->checkout($accessLog);

        return response()->json(['data' => $updated]);
    }

    /**
     * Append an immutable correction note to an access log entry.
     */
    public function addCorrection(Request $request, string $id): JsonResponse
    {
        $this->authorize('update.access_log', AccessLog::class);

        $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $accessLog = AccessLog::findOrFail($id);

        if ($accessLog->entered_at === null && $accessLog->denied_at === null) {
            return response()->json(['message' => 'No se puede corregir un registro sin entrada ni denegación.'], 422);
        }

        $updated = app(AccessService::class)->addCorrection($accessLog, $request->input('note'));

        return response()->json(['data' => $updated]);
    }

    /**
     * Shared §14.4.4 capture rules: tenant-scoped unit + identification + vehicle +
     * motive + optional photo. unit_id MUST be tenant-scoped (single shared DB).
     *
     * @param  array<string, array<int, mixed>>  $extra
     * @return array<string, array<int, mixed>>
     */
    private function manualCaptureRules(array $extra): array
    {
        return array_merge([
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'id_document_type' => ['nullable', 'string', Rule::in(['ine', 'passport', 'driver_license', 'other'])],
            'id_document_number' => ['nullable', 'string', 'max:100'],
            'plate' => ['nullable', 'string', 'max:20'],
            'visitor_motive_id' => ['nullable', 'uuid', Rule::exists('visitor_motive', 'id')->where('tenant_id', tenant('id'))],
            'photo' => ['nullable', 'image', 'max:5120'],
        ], $extra);
    }
}
