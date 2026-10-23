<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\StimulationGroupType;
use QCH\Core\Http\Controllers\Controller;

class StimulationGroupTypeController extends Controller
{
    protected $model = StimulationGroupType::class;

    protected $keyForPermission = 'stimulation_group_type';

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
