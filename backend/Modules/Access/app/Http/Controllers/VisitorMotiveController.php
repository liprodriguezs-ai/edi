<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\VisitorMotive;
use QCH\Core\Http\Controllers\Controller;

class VisitorMotiveController extends Controller
{
    protected $model = VisitorMotive::class;

    protected $keyForPermission = 'visitor_motive';

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
