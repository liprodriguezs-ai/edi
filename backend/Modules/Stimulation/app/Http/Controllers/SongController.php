<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\Song;
use QCH\Core\Http\Controllers\Controller;

class SongController extends Controller
{
    protected $model = Song::class;

    protected $keyForPermission = 'song';

    public function sortableBy(): array
    {
        return ['name', 'is_active', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
