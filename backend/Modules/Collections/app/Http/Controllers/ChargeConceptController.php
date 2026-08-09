<?php

namespace Modules\Collections\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Collections\Models\ChargeConcept;
use QCH\Core\Http\Controllers\Controller;

class ChargeConceptController extends Controller
{
    protected $model = ChargeConcept::class;

    protected $keyForPermission = 'charge_concept';

    public function includes(): array
    {
        return ['category', 'policies'];
    }

    public function searchableBy(): array
    {
        return ['code', 'name', 'description'];
    }

    public function sortableBy(): array
    {
        return ['code', 'name', 'is_active'];
    }

    public function filterableBy(): array
    {
        return ['is_active', 'charge_concept_category_id'];
    }

    /**
     * §8.3.2 (last rule — finding collections-14): a concept with associated policies
     * or charges cannot be deleted; it can only be deactivated. Deleting it would leave
     * historical charges hanging off a soft-deleted concept while the partial unique
     * index lets the same code be recreated.
     */
    protected function performDestroy(Model $entity): void
    {
        /** @var ChargeConcept $entity */
        if ($entity->policies()->exists() || $entity->charges()->exists()) {
            abort(422, 'Este concepto tiene políticas o cobros asociados; desactívalo en su lugar.');
        }

        $entity->delete();
    }
}
