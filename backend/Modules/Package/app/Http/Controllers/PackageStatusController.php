<?php

namespace Modules\Package\Http\Controllers;

use Modules\Package\Models\PackageStatus;
use QCH\Core\Http\Controllers\Controller;

/**
 * Central FSM catalog — read-only for tenant roles.
 * Only index/show are routed (see routes/api.php ->only(['index','show'])), so no
 * tenant can corrupt the platform-wide state machine.
 */
class PackageStatusController extends Controller
{
    protected $model = PackageStatus::class;

    protected $keyForPermission = 'package_status';
}
