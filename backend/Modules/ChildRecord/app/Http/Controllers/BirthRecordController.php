<?php

namespace Modules\ChildRecord\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\ChildRecord\Http\Requests\BirthRecordCreateRequest;
use Modules\ChildRecord\Http\Requests\BirthRecordUpdateRequest;
use Modules\ChildRecord\Models\BirthRecord;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * D11 — birth_record is a sub-resource of the child clinical record. It has its
 * OWN permission (odd/tasks/edi-agenda.md S16): perinatal history is clinical,
 * so the front desk can manage the child registry without editing it.
 */
class BirthRecordController extends Controller
{
    protected $model = BirthRecord::class;

    protected $keyForPermission = 'birth_record';

    public function includes(): array
    {
        return ['child'];
    }

    public function filterableBy(): array
    {
        return ['child_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(BirthRecordCreateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(BirthRecordUpdateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }
}
