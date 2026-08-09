<?php

namespace Modules\Projects\Http\Controllers;

use Modules\Projects\Models\ParticipationType;
use QCH\Core\Http\Controllers\Controller;

class ParticipationTypeController extends Controller
{
    protected $model = ParticipationType::class;

    protected $keyForPermission = 'participation_type';

    public function sortableBy(): array
    {
        return ['name', 'key', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
