<?php

namespace Modules\Collaborator\Http\Controllers;

use Modules\Collaborator\Models\PayFrequency;
use QCH\Core\Http\Controllers\Controller;

class PayFrequencyController extends Controller
{
    protected $model = PayFrequency::class;

    protected $keyForPermission = 'pay_frequency';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
