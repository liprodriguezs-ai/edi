<?php

namespace Modules\Board\Http\Controllers;

use Modules\Board\Models\BoardPeriodStatus;
use QCH\Core\Http\Controllers\Controller;

class BoardPeriodStatusController extends Controller
{
    protected $model = BoardPeriodStatus::class;

    protected $keyForPermission = 'board_period_status';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
