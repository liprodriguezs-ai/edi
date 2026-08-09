<?php

namespace Modules\Housing\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Housing\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Housing\Models\UnitUser;
use Modules\Housing\Services\UnitUserService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class UnitUserController extends Controller
{
    use ScopesToResidentUnits;

    protected $model = UnitUser::class;

    protected $keyForPermission = 'unit_user';

    public function includes(): array
    {
        return ['unit', 'unitRelationship', 'user'];
    }

    public function searchableBy(): array
    {
        return ['notes', 'unit.number'];
    }

    public function sortableBy(): array
    {
        return ['start_date', 'end_date', 'created_at', 'is_billing_owner', 'is_legal_representative'];
    }

    public function filterableBy(): array
    {
        return ['unit_id', 'user_id', 'unit_relationship_id', 'is_billing_owner', 'is_legal_representative', 'end_date'];
    }

    /**
     * Store/update validation runs via the core DelegatingModuleRequest wiring
     * (UnitUserCreateRequest / UnitUserUpdateRequest), so validated() carries the
     * real payload here. Business invariants (§6.3.4) live in UnitUserService.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $created = app(UnitUserService::class)->create($request->validated());
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        app(UnitUserService::class)->update($entity, $request->validated());
    }
}
