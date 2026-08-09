<?php

namespace Modules\Announcements\Http\Controllers;

use Modules\Announcements\Models\AnnouncementPriority;
use QCH\Core\Http\Controllers\Controller;

class AnnouncementPriorityController extends Controller
{
    protected $model = AnnouncementPriority::class;

    protected $keyForPermission = 'announcement_priority';

    public function sortableBy(): array
    {
        return ['name', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
