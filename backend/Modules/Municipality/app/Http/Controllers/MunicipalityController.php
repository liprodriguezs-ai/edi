<?php

namespace Modules\Municipality\Http\Controllers;

use Modules\Municipality\Models\Municipality;
use QCH\Core\Http\Controllers\Controller;

class MunicipalityController extends Controller
{
    protected $model = Municipality::class;

    protected $keyForPermission = 'municipality';
}
