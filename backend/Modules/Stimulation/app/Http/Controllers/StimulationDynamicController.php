<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\StimulationDynamic;
use QCH\Core\Http\Controllers\Controller;

class StimulationDynamicController extends Controller
{
    protected $model = StimulationDynamic::class;

    protected $keyForPermission = 'stimulation_dynamic';

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
