<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\BlacklistType;
use QCH\Core\Http\Controllers\Controller;

class BlacklistTypeController extends Controller
{
    protected $model = BlacklistType::class;

    protected $keyForPermission = 'blacklist_type';

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
