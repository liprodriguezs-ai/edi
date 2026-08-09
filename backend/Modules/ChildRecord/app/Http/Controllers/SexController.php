<?php

namespace Modules\ChildRecord\Http\Controllers;

use Modules\ChildRecord\Models\Sex;
use QCH\Core\Http\Controllers\Controller;

class SexController extends Controller
{
    protected $model = Sex::class;

    protected $keyForPermission = 'sex';

    public function sortableBy(): array
    {
        return ['name', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
