<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\VisitorStatus;
use QCH\Core\Http\Controllers\Controller;

class VisitorStatusController extends Controller
{
    protected $model = VisitorStatus::class;

    protected $keyForPermission = 'visitor_status';

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
