<?php

namespace Modules\Collaborator\Http\Controllers;

use Modules\Collaborator\Models\EmployeePosition;
use QCH\Core\Http\Controllers\Controller;

class EmployeePositionController extends Controller
{
    protected $model = EmployeePosition::class;

    protected $keyForPermission = 'employee_position';

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
