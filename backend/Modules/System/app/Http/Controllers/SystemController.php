<?php

namespace Modules\System\Http\Controllers;

use Modules\System\Models\System;
use QCH\Core\Http\Controllers\Controller;

class SystemController extends Controller
{
    protected $model = System::class;

    protected $keyForPermission = 'system';

    public function includes(): array
    {
        return ['modules', 'tenants'];
    }

    public function sortableBy(): array
    {
        return ['name', 'slug', 'sort_order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'slug', 'description'];
    }
}
