<?php

namespace Modules\Board\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Services\BoardService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class BoardPeriodController extends Controller
{
    protected $model = BoardPeriod::class;

    protected $keyForPermission = 'board_period';

    public function includes(): array
    {
        return ['board_period_status', 'members', 'members.board_role', 'members.user', 'media'];
    }

    public function searchableBy(): array
    {
        return ['name', 'notes'];
    }

    public function sortableBy(): array
    {
        return ['name', 'start_date', 'end_date', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['board_period_status_id'];
    }

    /**
     * Block the raw Orion create path: a board_period must ONLY be created through the
     * guarded POST /board_period/start flow (single-current guard, mandatory
     * Pres/Tes/Sec, role grant, no overlapping dates). Allowing arbitrary store would
     * let a caller set any status and bypass every invariant.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        abort(422, 'Use POST /board_period/start para crear un periodo de mesa directiva.');
    }

    /**
     * Start a new board period (transactional: auto-close previous + assign Spatie roles).
     */
    public function startPeriod(HttpRequest $request, BoardService $boardService): JsonResponse
    {
        $this->authorize('create.board_period', BoardPeriod::class);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('board_period', 'name')->where('tenant_id', tenant('id')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'notes' => ['nullable', 'string'],
            'auto_close_current' => ['sometimes', 'boolean'],
            'members' => ['required', 'array', 'min:1'],
            // §7.3.2: only members of THIS cluster can hold a seat — Spatie roles are
            // global, so an unscoped uuid here would allow cross-tenant escalation.
            'members.*.user_id' => [
                'required',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'members.*.board_role_id' => ['required', 'uuid', 'exists:board_role,id'],
            'members.*.unit_id' => ['nullable', 'uuid'],
        ]);

        try {
            $period = $boardService->startPeriod(
                periodData: $validated,
                membersData: $validated['members'],
            );

            return response()->json(['message' => 'Periodo iniciado correctamente.', 'data' => $period], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Close the given board period and revoke all member Spatie roles.
     */
    public function closePeriod(string $id, BoardService $boardService): JsonResponse
    {
        $this->authorize('update.board_period', BoardPeriod::class);

        $period = BoardPeriod::findOrFail($id);

        try {
            $updated = $boardService->closePeriod($period);

            return response()->json(['message' => 'Periodo cerrado correctamente.', 'data' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /board_period/{id}/media
     * Upload the period's constitutive act (§7.3.2 / §7.6.4) into the single-file
     * `acta` media collection. PDF only, max 10 MB.
     */
    public function uploadActa(string $id, HttpRequest $request): JsonResponse
    {
        $this->authorize('update.board_period', BoardPeriod::class);

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $period = BoardPeriod::findOrFail($id);

        $media = $period->addMedia($request->file('file'))
            ->toMediaCollection('acta');

        return response()->json(['data' => $media], 201);
    }
}
