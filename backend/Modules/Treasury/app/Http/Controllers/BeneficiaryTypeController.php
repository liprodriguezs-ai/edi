<?php

namespace Modules\Treasury\Http\Controllers;

use Modules\Treasury\Models\BeneficiaryType;
use QCH\Core\Http\Controllers\Controller;

class BeneficiaryTypeController extends Controller
{
    protected $model = BeneficiaryType::class;

    protected $keyForPermission = 'beneficiary_type';

    public function sortableBy(): array
    {
        return ['name', 'order', 'key'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
