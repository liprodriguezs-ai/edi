<?php

namespace Modules\ChildRecord\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\ChildRecord\Http\Requests\ChildCaregiverCreateRequest;
use Modules\ChildRecord\Http\Requests\ChildCaregiverUpdateRequest;
use Modules\ChildRecord\Models\ChildCaregiver;
use Modules\ChildRecord\Services\ChildCaregiverService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * D11 — child_caregiver is a sub-resource of the child clinical record and
 * authorizes under the SAME permission as `child`.
 */
class ChildCaregiverController extends Controller
{
    protected $model = ChildCaregiver::class;

    protected $keyForPermission = 'child';

    public function includes(): array
    {
        return ['child', 'caregiver', 'caregiver_relationship'];
    }

    public function filterableBy(): array
    {
        return ['child_id', 'caregiver_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ChildCaregiverCreateRequest::class)->validated();
        $created = app(ChildCaregiverService::class)->create($validated);
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ChildCaregiverUpdateRequest::class)->validated();
        app(ChildCaregiverService::class)->update($entity, $validated);
    }

    protected function performDestroy(Model $entity): void
    {
        app(ChildCaregiverService::class)->delete($entity);
    }
}
