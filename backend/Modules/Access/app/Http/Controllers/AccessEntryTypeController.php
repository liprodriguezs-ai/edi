<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\AccessEntryType;
use QCH\Core\Http\Controllers\Controller;

class AccessEntryTypeController extends Controller
{
    protected $model = AccessEntryType::class;

    protected $keyForPermission = 'access_entry_type';

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
