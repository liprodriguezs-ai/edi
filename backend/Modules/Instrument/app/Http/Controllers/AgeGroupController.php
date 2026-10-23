<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\AgeGroup;
use QCH\Core\Http\Controllers\Controller;

class AgeGroupController extends Controller
{
    protected $model = AgeGroup::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['alternate'];
    }

    public function sortableBy(): array
    {
        return ['ordinal', 'code'];
    }

    public function searchableBy(): array
    {
        return ['code', 'name'];
    }

    public function filterableBy(): array
    {
        return ['instrument_version_id', 'is_alternate'];
    }
}
