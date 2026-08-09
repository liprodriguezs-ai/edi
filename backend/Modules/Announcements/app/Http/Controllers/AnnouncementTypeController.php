<?php

namespace Modules\Announcements\Http\Controllers;

use Modules\Announcements\Models\AnnouncementType;
use QCH\Core\Http\Controllers\Controller;

class AnnouncementTypeController extends Controller
{
    protected $model = AnnouncementType::class;

    protected $keyForPermission = 'announcement_type';

    public function sortableBy(): array
    {
        return ['name', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
