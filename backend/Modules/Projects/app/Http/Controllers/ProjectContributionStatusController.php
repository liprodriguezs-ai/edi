<?php

namespace Modules\Projects\Http\Controllers;

use Modules\Projects\Models\ProjectContributionStatus;
use QCH\Core\Http\Controllers\Controller;

class ProjectContributionStatusController extends Controller
{
    protected $model = ProjectContributionStatus::class;

    protected $keyForPermission = 'project_contribution_status';

    public function sortableBy(): array
    {
        return ['name', 'key', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
