<?php

namespace Modules\ChildRecord\Http\Controllers;

use Modules\ChildRecord\Models\EducationLevel;
use QCH\Core\Http\Controllers\Controller;

class EducationLevelController extends Controller
{
    protected $model = EducationLevel::class;

    protected $keyForPermission = 'education_level';

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
