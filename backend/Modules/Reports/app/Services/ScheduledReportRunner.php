<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\ChildRecord\Models\Child;
use Modules\Notification\Services\NotificationService;
use Modules\Reports\Exports\ScheduledReportExport;
use Modules\Reports\Models\ScheduledReport;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use Modules\Screening\Models\Screening;
use QCH\Core\Support\TenantTime;

/**
 * Generates one saved report and delivers it in-app (REQ-RPT-013).
 *
 * Runs INSIDE the owning organization's tenancy — the caller switches first — so
 * every query here is already scoped and the export cannot reach another
 * organization's rows.
 */
class ScheduledReportRunner
{
    /**
     * Mirrors ReportController::REPORT_MODEL_MAP. The `with` lists are the N+1
     * firewall: reportColumns() closures may only read these relations, so they
     * must be eager-loaded before the export iterates.
     *
     * @var array<string, array{class: class-string, with: array<int, string>}>
     */
    private const MODELS = [
        'Screening' => [
            'class' => Screening::class,
            'with' => [
                'child.sex', 'visit.health_center', 'visit.practitioner',
                'age_group', 'applied_age_group', 'result_color', 'situation', 'screening_status',
                'area_results.development_area', 'area_results.result_color',
            ],
        ],
        'Child' => [
            'class' => Child::class,
            'with' => ['sex', 'health_center'],
        ],
        'ScreeningIndicator' => [
            'class' => ScreeningIndicatorSnapshot::class,
            'with' => ['health_center'],
        ],
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    public function run(ScheduledReport $schedule): void
    {
        $config = self::MODELS[$schedule->report_model_key]
            ?? throw new \InvalidArgumentException("Modelo de reporte desconocido: {$schedule->report_model_key}");

        $model = app($config['class']);
        $columns = $model->reportColumns();

        $rows = $model->newQuery()->with($config['with'])->get();

        $path = 'reports/scheduled/'.$schedule->id.'/'.Str::slug($schedule->name).'-'.TenantTime::inTenantZone(now())->format('Ymd-His').'.xlsx';

        Excel::store(new ScheduledReportExport($rows, $columns), $path, config('filesystems.default'));

        $this->notifications->create([
            'title' => 'Reporte programado listo',
            'message' => "Tu reporte «{$schedule->name}» ya está disponible para descargar.",
            'click_action' => Storage::url($path),
            'date' => now(),
        ]);

        // Stamped LAST and quietly: last_run_at is the idempotency anchor, and it
        // must only move once the report actually exists and was delivered.
        $schedule->forceFill(['last_run_at' => now()])->saveQuietly();
    }
}
