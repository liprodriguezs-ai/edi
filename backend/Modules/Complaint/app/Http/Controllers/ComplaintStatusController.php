<?php

namespace Modules\Complaint\Http\Controllers;

use Modules\Complaint\Models\ComplaintStatus;
use QCH\Core\Http\Controllers\Controller;

/**
 * Central FSM catalog — read-only for tenant roles.
 * Only index/show are routed (see routes/api.php ->only(['index','show'])), so no
 * tenant can corrupt the platform-wide state machine.
 */
class ComplaintStatusController extends Controller
{
    protected $model = ComplaintStatus::class;

    protected $keyForPermission = 'complaint_status';
}
