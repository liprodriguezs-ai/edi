<?php

namespace Modules\Projects\Http\Controllers;

use Modules\Projects\Models\CommunityProjectCategory;
use QCH\Core\Http\Controllers\Controller;

class CommunityProjectCategoryController extends Controller
{
    protected $model = CommunityProjectCategory::class;

    protected $keyForPermission = 'community_project_category';

    public function sortableBy(): array
    {
        return ['name', 'key', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
