<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\BodyZone;
use QCH\Core\Http\Controllers\Controller;

class BodyZoneController extends Controller
{
    protected $model = BodyZone::class;

    protected $keyForPermission = 'body_zone';

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
