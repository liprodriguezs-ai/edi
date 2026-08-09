<?php

namespace Modules\Audit\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Audit\Models\AuditFixed;
use Modules\Gamification\Models\Gamification;
use QCH\Core\Models\Audit;

class AuditObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Audit $audit): void
    {
        Log::info($audit->auditable_type);
        if ($audit->auditable_type == Gamification::class) {
            $auditFixed = new AuditFixed;
            $auditFixed->fill($audit->toArray());
            $auditFixed->save();
        }
    }
}
