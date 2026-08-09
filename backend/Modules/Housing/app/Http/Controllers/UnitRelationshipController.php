<?php

namespace Modules\Housing\Http\Controllers;

use Modules\Housing\Models\UnitRelationship;
use QCH\Core\Http\Controllers\Controller;

class UnitRelationshipController extends Controller
{
    protected $model = UnitRelationship::class;

    protected $keyForPermission = 'unit_relationship';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
