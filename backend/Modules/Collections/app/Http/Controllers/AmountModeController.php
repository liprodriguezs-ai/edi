<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\AmountMode;
use QCH\Core\Http\Controllers\Controller;

class AmountModeController extends Controller
{
    protected $model = AmountMode::class;

    protected $keyForPermission = 'amount_mode';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
