<?php

namespace Modules\Projects\Http\Controllers;

use Modules\Projects\Models\CommunityProjectStatus;
use QCH\Core\Http\Controllers\Controller;

class CommunityProjectStatusController extends Controller
{
    protected $model = CommunityProjectStatus::class;

    protected $keyForPermission = 'community_project_status';

    public function sortableBy(): array
    {
        return ['name', 'key', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
