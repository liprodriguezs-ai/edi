<?php

namespace Modules\Housing\Http\Controllers;

use Modules\Housing\Models\Section;
use QCH\Core\Http\Controllers\Controller;

class SectionController extends Controller
{
    protected $model = Section::class;

    protected $keyForPermission = 'section';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['order'];
    }
}
