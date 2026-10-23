<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\AreaScoringRule;
use QCH\Core\Http\Controllers\Controller;

class AreaScoringRuleController extends Controller
{
    protected $model = AreaScoringRule::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['age_group', 'development_area'];
    }

    public function filterableBy(): array
    {
        return ['instrument_version_id', 'age_group_id', 'development_area_id'];
    }
}
