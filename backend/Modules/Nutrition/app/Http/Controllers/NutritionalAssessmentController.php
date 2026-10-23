<?php

namespace Modules\Nutrition\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\ChildRecord\Models\Child;
use Modules\Nutrition\Http\Requests\NutritionalAssessmentClassifyRequest;
use Modules\Nutrition\Http\Requests\NutritionalAssessmentCreateRequest;
use Modules\Nutrition\Http\Requests\NutritionalAssessmentUpdateRequest;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Services\NutritionalAssessmentService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * S5 -- nutritional_assessment CRUD. The statuses and age are computed by
 * NutritionalAssessmentService; assessments synced from a Prueba EDI are
 * edited from the Prueba EDI, never here (only their notes).
 */
class NutritionalAssessmentController extends Controller
{
    private const SYNCED_FIELDS = ['measured_on', 'weight_grams', 'height_mm'];

    private const SYNCED_MESSAGE = 'Esta medición proviene de una Prueba EDI; modifícala desde la Prueba EDI.';

    protected $model = NutritionalAssessment::class;

    protected $keyForPermission = 'nutritional_assessment';

    public function includes(): array
    {
        return ['child', 'screening', 'weight_for_height_status', 'weight_for_age_status', 'height_for_age_status'];
    }

    public function alwaysIncludes(): array
    {
        return ['weight_for_height_status', 'weight_for_age_status', 'height_for_age_status'];
    }

    public function filterableBy(): array
    {
        return ['child_id', 'screening_id', 'measured_on'];
    }

    public function sortableBy(): array
    {
        return ['measured_on', 'created_at'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(NutritionalAssessmentCreateRequest::class)->validated();
        $child = Child::findOrFail($validated['child_id']);

        app(NutritionalAssessmentService::class)->fill(
            $entity,
            $child,
            $validated['measured_on'],
            $validated['weight_grams'] ?? null,
            $validated['height_mm'] ?? null,
        );
        $entity->fill(['notes' => $validated['notes'] ?? null]);
        $entity->save();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(NutritionalAssessmentUpdateRequest::class)->validated();

        if ($entity->screening_id !== null) {
            $touched = array_intersect(self::SYNCED_FIELDS, array_keys($validated));
            if ($touched !== []) {
                throw ValidationException::withMessages(array_fill_keys($touched, self::SYNCED_MESSAGE));
            }
        } else {
            app(NutritionalAssessmentService::class)->fill(
                $entity,
                $entity->child()->firstOrFail(),
                $validated['measured_on'] ?? $entity->measured_on->toDateString(),
                Arr::exists($validated, 'weight_grams') ? $validated['weight_grams'] : $entity->weight_grams,
                Arr::exists($validated, 'height_mm') ? $validated['height_mm'] : $entity->height_mm,
            );
        }

        if (Arr::exists($validated, 'notes')) {
            $entity->fill(['notes' => $validated['notes']]);
        }
        $entity->save();
    }

    protected function performDestroy(Model $entity): void
    {
        if ($entity->screening_id !== null) {
            throw ValidationException::withMessages(['screening_id' => self::SYNCED_MESSAGE]);
        }

        parent::performDestroy($entity);
    }

    /**
     * GET /api/nutritional_assessment/classify -- server-computed preview for
     * the capture form; persists nothing.
     */
    public function classify(NutritionalAssessmentClassifyRequest $request, NutritionalAssessmentService $service): JsonResponse
    {
        $validated = $request->validated();
        $child = Child::findOrFail($validated['child_id']);

        return response()->json([
            'data' => $service->preview(
                $child,
                $validated['measured_on'],
                isset($validated['weight_grams']) ? (int) $validated['weight_grams'] : null,
                isset($validated['height_mm']) ? (int) $validated['height_mm'] : null,
            ),
        ]);
    }
}
