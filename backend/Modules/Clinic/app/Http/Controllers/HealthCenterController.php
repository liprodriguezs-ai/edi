<?php

namespace Modules\Clinic\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Clinic\Http\Requests\HealthCenterCreateRequest;
use Modules\Clinic\Http\Requests\HealthCenterUpdateRequest;
use Modules\Clinic\Models\HealthCenter;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class HealthCenterController extends Controller
{
    protected $model = HealthCenter::class;

    protected $keyForPermission = 'health_center';

    public function includes(): array
    {
        return ['municipality'];
    }

    public function sortableBy(): array
    {
        return ['name', 'clues_code', 'is_active', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name', 'clues_code'];
    }

    public function filterableBy(): array
    {
        return ['municipality_id', 'is_active'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(HealthCenterCreateRequest::class)->validated();
        $entity->fill($validated);
        $this->saveOrConflict($entity);
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(HealthCenterUpdateRequest::class)->validated();
        $entity->fill($validated);
        $this->saveOrConflict($entity);
    }

    /**
     * REQ-CL-002 — the FormRequest's per-tenant `Rule::unique` pre-check runs
     * against the RAW (still un-normalized) clues_code, since Orion's request
     * pipeline in this project validates before prepareForValidation() can
     * uppercase it. A case-different duplicate (e.g. existing "DFSSA003962" vs
     * submitted "dfssa003962") slips that pre-check but is still caught here
     * by the DB partial unique index, once the value is normalized — mapped to
     * a friendly 422 instead of a raw 500.
     */
    private function saveOrConflict(Model $entity): void
    {
        try {
            $entity->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'clues_code' => ['Ya existe un centro de salud con este código CLUES en tu organización.'],
            ]);
        }
    }
}
