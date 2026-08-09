<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\ChargeBatchStatus;
use QCH\Core\Http\Controllers\Controller;

class ChargeBatchStatusController extends Controller
{
    protected $model = ChargeBatchStatus::class;

    protected $keyForPermission = 'charge_batch_status';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
