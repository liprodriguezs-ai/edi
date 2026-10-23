<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\MassageMovement;
use QCH\Core\Http\Controllers\Controller;

class MassageMovementController extends Controller
{
    protected $model = MassageMovement::class;

    protected $keyForPermission = 'massage_movement';

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
