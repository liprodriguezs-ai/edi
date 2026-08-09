<?php

namespace Modules\ChildRecord\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\ChildRecord\Http\Requests\CaregiverCreateRequest;
use Modules\ChildRecord\Http\Requests\CaregiverUpdateRequest;
use Modules\ChildRecord\Models\Caregiver;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class CaregiverController extends Controller
{
    protected $model = Caregiver::class;

    protected $keyForPermission = 'caregiver';

    public function includes(): array
    {
        return ['education_level', 'occupation', 'population_type'];
    }

    public function sortableBy(): array
    {
        return ['first_name', 'last_name_paternal', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['first_name', 'last_name_paternal', 'last_name_maternal', 'phone'];
    }

    public function filterableBy(): array
    {
        return ['education_level_id', 'occupation_id', 'population_type_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(CaregiverCreateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(CaregiverUpdateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }
}
