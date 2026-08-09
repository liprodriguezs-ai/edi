<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\SurchargeMode;
use QCH\Core\Http\Controllers\Controller;

class SurchargeModeController extends Controller
{
    protected $model = SurchargeMode::class;

    protected $keyForPermission = 'surcharge_mode';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
