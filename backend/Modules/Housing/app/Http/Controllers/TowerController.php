<?php

namespace Modules\Housing\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Housing\Models\Tower;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class TowerController extends Controller
{
    protected $model = Tower::class;

    protected $keyForPermission = 'tower';

    public function includes(): array
    {
        return ['section'];
    }

    public function searchableBy(): array
    {
        return ['name', 'section.name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'floors_count', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['section_id', 'order'];
    }

    /**
     * Guard: block deleting a tower that has units.
     * Spec §16.4 rule 1: structure changes that would delete a tower with units → block with alert.
     */
    protected function beforeDestroy(Request $request, Model $entity): void
    {
        /** @var Tower $entity */
        if ($entity->hasUnits()) {
            throw new UnprocessableEntityHttpException(
                'No se puede eliminar la torre porque tiene unidades asignadas. Reasigna o elimina las unidades primero.'
            );
        }
    }
}
