<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\StimulationArea;
use QCH\Core\Http\Controllers\Controller;

class StimulationAreaController extends Controller
{
    protected $model = StimulationArea::class;

    protected $keyForPermission = 'stimulation_area';

    public function sortableBy(): array
    {
        return ['name', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
