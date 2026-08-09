<?php

namespace Modules\Clinic\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Clinic\Http\Requests\PractitionerProfileCreateRequest;
use Modules\Clinic\Http\Requests\PractitionerProfileUpdateRequest;
use Modules\Clinic\Models\PractitionerProfile;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class PractitionerProfileController extends Controller
{
    protected $model = PractitionerProfile::class;

    protected $keyForPermission = 'practitioner_profile';

    public function includes(): array
    {
        return ['user', 'health_center'];
    }

    public function sortableBy(): array
    {
        return ['license_number', 'specialty', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['license_number', 'specialty'];
    }

    public function filterableBy(): array
    {
        return ['health_center_id', 'user_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(PractitionerProfileCreateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(PractitionerProfileUpdateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }
}
