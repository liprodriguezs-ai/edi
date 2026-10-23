<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\RiskFactor;
use QCH\Core\Http\Controllers\Controller;

class RiskFactorController extends Controller
{
    protected $model = RiskFactor::class;

    protected $keyForPermission = 'instrument';

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
        return ['instrument_version_id'];
    }
}
