<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\DidacticMaterial;
use QCH\Core\Http\Controllers\Controller;

class DidacticMaterialController extends Controller
{
    protected $model = DidacticMaterial::class;

    protected $keyForPermission = 'didactic_material';

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
