<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\InstrumentVersion;
use QCH\Core\Http\Controllers\Controller;

class InstrumentVersionController extends Controller
{
    protected $model = InstrumentVersion::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['instrument'];
    }

    public function sortableBy(): array
    {
        return ['key', 'name', 'published_at', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['key', 'name'];
    }

    public function filterableBy(): array
    {
        return ['instrument_id', 'is_default'];
    }
}
