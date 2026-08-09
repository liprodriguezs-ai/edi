<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\Periodicity;
use QCH\Core\Http\Controllers\Controller;

class PeriodicityController extends Controller
{
    protected $model = Periodicity::class;

    protected $keyForPermission = 'periodicity';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
