<?php

namespace Modules\Housing\Http\Controllers;

use Modules\Housing\Models\UnitStatus;
use QCH\Core\Http\Controllers\Controller;

class UnitStatusController extends Controller
{
    protected $model = UnitStatus::class;

    protected $keyForPermission = 'unit_status';

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
