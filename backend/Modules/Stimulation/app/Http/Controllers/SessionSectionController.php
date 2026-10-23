<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\SessionSection;
use QCH\Core\Http\Controllers\Controller;

class SessionSectionController extends Controller
{
    protected $model = SessionSection::class;

    protected $keyForPermission = 'session_section';

    public function sortableBy(): array
    {
        return ['name', 'sort_order', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
