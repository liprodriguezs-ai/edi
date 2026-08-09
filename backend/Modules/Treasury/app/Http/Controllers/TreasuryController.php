<?php

namespace Modules\Treasury\Http\Controllers;

use Modules\Treasury\Models\Treasury;
use QCH\Core\Http\Controllers\Controller;

class TreasuryController extends Controller
{
    protected $model = Treasury::class;

    protected $keyForPermission = 'treasury';
}
