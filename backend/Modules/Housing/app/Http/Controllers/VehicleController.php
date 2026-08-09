<?php

namespace Modules\Housing\Http\Controllers;

use Modules\Housing\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Housing\Models\Vehicle;
use QCH\Core\Http\Controllers\Controller;

class VehicleController extends Controller
{
    use ScopesToResidentUnits;

    protected $model = Vehicle::class;

    protected $keyForPermission = 'vehicle';

    public function includes(): array
    {
        return ['unit'];
    }

    public function searchableBy(): array
    {
        return ['plate', 'make', 'model', 'color'];
    }

    public function sortableBy(): array
    {
        return ['plate', 'make', 'model', 'year', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['unit_id', 'year', 'color'];
    }
}
