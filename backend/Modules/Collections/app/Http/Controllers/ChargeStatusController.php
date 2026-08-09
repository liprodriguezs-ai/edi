<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\ChargeStatus;
use QCH\Core\Http\Controllers\Controller;

class ChargeStatusController extends Controller
{
    protected $model = ChargeStatus::class;

    protected $keyForPermission = 'charge_status';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
