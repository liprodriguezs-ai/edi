<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\GlobalScoringRule;
use QCH\Core\Http\Controllers\Controller;

class GlobalScoringRuleController extends Controller
{
    protected $model = GlobalScoringRule::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['age_group', 'result_color', 'message'];
    }

    public function sortableBy(): array
    {
        return ['sequence'];
    }

    public function filterableBy(): array
    {
        return ['instrument_version_id', 'age_group_id'];
    }
}
