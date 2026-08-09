<?php

namespace Modules\ChildRecord\Http\Controllers;

use Modules\ChildRecord\Models\Occupation;
use QCH\Core\Http\Controllers\Controller;

class OccupationController extends Controller
{
    protected $model = Occupation::class;

    protected $keyForPermission = 'occupation';

    public function sortableBy(): array
    {
        return ['name', 'is_active', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
