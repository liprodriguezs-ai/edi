<?php

namespace Modules\Housing\Http\Controllers;

use Modules\Housing\Models\UnitType;
use QCH\Core\Http\Controllers\Controller;

class UnitTypeController extends Controller
{
    protected $model = UnitType::class;

    protected $keyForPermission = 'unit_type';

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
