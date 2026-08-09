<?php

namespace Modules\ChildRecord\Http\Controllers;

use Modules\ChildRecord\Models\PopulationType;
use QCH\Core\Http\Controllers\Controller;

class PopulationTypeController extends Controller
{
    protected $model = PopulationType::class;

    protected $keyForPermission = 'population_type';

    public function sortableBy(): array
    {
        return ['name', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
