<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\HeadCircumferenceReference;
use QCH\Core\Http\Controllers\Controller;

class HeadCircumferenceReferenceController extends Controller
{
    protected $model = HeadCircumferenceReference::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['sex'];
    }

    public function sortableBy(): array
    {
        return ['age_months'];
    }

    public function filterableBy(): array
    {
        return ['instrument_version_id', 'sex_id'];
    }
}
