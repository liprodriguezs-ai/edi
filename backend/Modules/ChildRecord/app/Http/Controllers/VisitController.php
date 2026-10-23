<?php

namespace Modules\ChildRecord\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\ChildRecord\Http\Requests\VisitCreateRequest;
use Modules\ChildRecord\Http\Requests\VisitUpdateRequest;
use Modules\ChildRecord\Models\Visit;
use Modules\ChildRecord\Services\VisitService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class VisitController extends Controller
{
    protected $model = Visit::class;

    protected $keyForPermission = 'visit';

    public function includes(): array
    {
        // practitioner.user: a practitioner's NAME lives on their user, so every
        // screen showing "Profesional" reads practitioner.user.name. Without the
        // nested relation that resolved to undefined and rendered a dash on every
        // row while the data was there all along.
        return ['child', 'health_center', 'practitioner', 'practitioner.user'];
    }

    public function sortableBy(): array
    {
        return ['visit_date', 'receipt_number', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['receipt_number', 'physical_receipt_number'];
    }

    /**
     * REQ-CH-008 — child visit history is served by the composite index
     * (tenant_id, child_id, visit_date DESC): filter by child_id and sort by
     * visit_date desc.
     */
    public function filterableBy(): array
    {
        return ['child_id', 'health_center_id', 'practitioner_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(VisitCreateRequest::class)->validated();
        $created = app(VisitService::class)->create($validated);
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(VisitUpdateRequest::class)->validated();
        app(VisitService::class)->update($entity, $validated);
    }
}
