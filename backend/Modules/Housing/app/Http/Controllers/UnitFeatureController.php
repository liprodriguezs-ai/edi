<?php

namespace Modules\Housing\Http\Controllers;

use Modules\Housing\Models\UnitFeature;
use QCH\Core\Http\Controllers\Controller;

class UnitFeatureController extends Controller
{
    protected $model = UnitFeature::class;

    protected $keyForPermission = 'unit_feature';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'is_active', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
