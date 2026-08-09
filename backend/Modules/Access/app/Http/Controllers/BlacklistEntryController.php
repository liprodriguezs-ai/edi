<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\BlacklistEntry;
use QCH\Core\Http\Controllers\Controller;

class BlacklistEntryController extends Controller
{
    protected $model = BlacklistEntry::class;

    protected $keyForPermission = 'blacklist_entry';

    public function includes(): array
    {
        return ['blacklist_type'];
    }

    public function filterableBy(): array
    {
        return ['blacklist_type_id', 'is_active', 'created_at'];
    }

    public function sortableBy(): array
    {
        return ['value', 'created_at', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['value', 'reason'];
    }
}
