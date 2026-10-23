<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\ScreeningItem;
use QCH\Core\Http\Controllers\Controller;

class ScreeningItemController extends Controller
{
    protected $model = ScreeningItem::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['options', 'options.result_color', 'development_area', 'application_position'];
    }

    public function sortableBy(): array
    {
        return ['sort_order', 'code'];
    }

    public function searchableBy(): array
    {
        return ['code', 'question'];
    }

    public function filterableBy(): array
    {
        return ['instrument_version_id', 'age_group_id', 'development_area_id'];
    }
}
