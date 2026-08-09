<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Reports\Models\ScheduledReport;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class ScheduledReportController extends Controller
{
    protected $model = ScheduledReport::class;

    protected $keyForPermission = 'scheduled_report';

    public function filterableBy(): array
    {
        return ['report_key', 'frequency', 'is_active'];
    }

    public function sortableBy(): array
    {
        return ['report_key', 'next_run_at', 'created_at', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['report_key', 'frequency'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $report = new ScheduledReport($attributes);
        $report->computeNextRun();
        $report->save();

        $entity->setRawAttributes($report->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $entity->fill($attributes);
        $entity->computeNextRun();
        $entity->save();
    }
}
