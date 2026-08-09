<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\ChargeConceptCategory;
use QCH\Core\Http\Controllers\Controller;

class ChargeConceptCategoryController extends Controller
{
    protected $model = ChargeConceptCategory::class;

    protected $keyForPermission = 'charge_concept_category';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
