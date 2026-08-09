<?php

namespace Modules\Module\Http\Controllers;

use Modules\Module\Models\Module;
use QCH\Core\Http\Controllers\Controller;

class ModuleController extends Controller
{
    protected $model = Module::class;

    protected $keyForPermission = 'module';
}
