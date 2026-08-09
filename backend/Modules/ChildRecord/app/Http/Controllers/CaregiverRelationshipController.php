<?php

namespace Modules\ChildRecord\Http\Controllers;

use Modules\ChildRecord\Models\CaregiverRelationship;
use QCH\Core\Http\Controllers\Controller;

class CaregiverRelationshipController extends Controller
{
    protected $model = CaregiverRelationship::class;

    protected $keyForPermission = 'caregiver_relationship';

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
