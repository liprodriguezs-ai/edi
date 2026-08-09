<?php

namespace Modules\Reports\Http\Controllers;

use Modules\Reports\Models\Reports;
use QCH\Core\Http\Controllers\Controller;

class ReportsController extends Controller
{
    protected $model = Reports::class;

    protected $keyForPermission = 'reports';
}
