<?php

namespace Modules\Stimulation\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Stimulation\Http\Requests\StimulationSessionCreateRequest;
use Modules\Stimulation\Http\Requests\StimulationSessionUpdateRequest;
use Modules\Stimulation\Models\StimulationSession;
use Modules\Stimulation\Services\StimulationSessionService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class StimulationSessionController extends Controller
{
    protected $model = StimulationSession::class;

    protected $keyForPermission = 'stimulation_session';

    public function includes(): array
    {
        return [
            'child',
            'stimulation_group',
            'visit',
            'practitioner',
            'integration_dynamic',
            'core_dynamic',
            'core_stimulation_area',
            'movements',
            'relaxation_zones',
        ];
    }

    public function sortableBy(): array
    {
        return ['session_date', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['child_id', 'stimulation_group_id', 'practitioner_id', 'session_date'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(StimulationSessionCreateRequest::class)->validated();
        $created = app(StimulationSessionService::class)->create($validated);
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(StimulationSessionUpdateRequest::class)->validated();
        app(StimulationSessionService::class)->update($entity, $validated);
    }
}
