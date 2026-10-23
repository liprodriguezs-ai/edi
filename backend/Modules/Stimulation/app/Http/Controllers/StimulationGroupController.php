<?php

namespace Modules\Stimulation\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Stimulation\Http\Requests\StimulationGroupCreateRequest;
use Modules\Stimulation\Http\Requests\StimulationGroupUpdateRequest;
use Modules\Stimulation\Models\StimulationGroup;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class StimulationGroupController extends Controller
{
    protected $model = StimulationGroup::class;

    protected $keyForPermission = 'stimulation_group';

    public function includes(): array
    {
        return ['stimulation_group_type', 'health_center', 'promoter'];
    }

    public function sortableBy(): array
    {
        return ['name', 'start_date', 'is_active', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function filterableBy(): array
    {
        return ['health_center_id', 'is_active'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(StimulationGroupCreateRequest::class)->validated();
        $entity->fill($validated);
        $this->saveOrConflict($entity);
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(StimulationGroupUpdateRequest::class)->validated();
        $entity->fill($validated);
        $this->saveOrConflict($entity);
    }

    /**
     * REQ-SG-001 — the FormRequest's per-tenant Rule::unique pre-check can be
     * beaten by a genuine race; the DB partial unique index is the real
     * backstop, mapped to a friendly 422 instead of a raw 500.
     */
    private function saveOrConflict(Model $entity): void
    {
        try {
            $entity->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe un grupo con este nombre en tu organización.'],
            ]);
        }
    }
}
