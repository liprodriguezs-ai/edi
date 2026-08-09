<?php

namespace Modules\Announcements\Http\Controllers;

use Modules\Announcements\Models\AnnouncementStatus;
use QCH\Core\Http\Controllers\Controller;

class AnnouncementStatusController extends Controller
{
    protected $model = AnnouncementStatus::class;

    protected $keyForPermission = 'announcement_status';

    public function sortableBy(): array
    {
        return ['name', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
