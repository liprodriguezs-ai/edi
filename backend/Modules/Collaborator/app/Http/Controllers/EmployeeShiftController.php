<?php

namespace Modules\Collaborator\Http\Controllers;

use Modules\Collaborator\Models\EmployeeShift;
use QCH\Core\Http\Controllers\Controller;

class EmployeeShiftController extends Controller
{
    protected $model = EmployeeShift::class;

    protected $keyForPermission = 'employee_shift';

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
