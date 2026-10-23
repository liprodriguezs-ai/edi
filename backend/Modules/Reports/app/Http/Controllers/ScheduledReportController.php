<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Reports\Http\Requests\ScheduledReportCreateRequest;
use Modules\Reports\Http\Requests\ScheduledReportUpdateRequest;
use Modules\Reports\Models\ScheduledReport;
use QCH\Core\Http\Controllers\Controller;

/**
 * CRUD for saved reports the nightly runner regenerates (REQ-RPT-013).
 *
 * `last_run_at` is deliberately absent from every request: it is the runner's
 * idempotency anchor, and a client able to set it could force a redelivery or
 * suppress one silently.
 */
class ScheduledReportController extends Controller
{
    protected $model = ScheduledReport::class;

    protected $keyForPermission = 'scheduled_report';

    public function includes(): array
    {
        return ['report_frequency', 'recipient'];
    }

    public function filterableBy(): array
    {
        return ['report_frequency_id', 'recipient_user_id', 'is_active', 'report_model_key'];
    }

    public function sortableBy(): array
    {
        return ['name', 'last_run_at', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $entity->fill(app(ScheduledReportCreateRequest::class)->validated());
        $entity->save();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $entity->fill(app(ScheduledReportUpdateRequest::class)->validated());
        $entity->save();
    }
}
