<?php

namespace Modules\Reports\Jobs;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Reports\Exports\ReportExport;
use Modules\Reports\Models\GeneratedReport;
use Modules\Reports\Services\ReportFormatter;
use Modules\Reports\Services\ReportRegistry;

/**
 * GenerateReportJob — runs report generation asynchronously.
 *
 * On the `sync` queue (dev/test) it runs inline inside dispatch().
 * On redis/database queues it runs as a background job.
 *
 * Flow:
 *  1. Mark generated_report as "processing".
 *  2. Initialize tenancy (QueueTenancyBootstrapper carries tenant_id into payload).
 *  3. Build report data via ReportService.
 *  4. Export to pdf|excel and store in Storage (tenant-namespaced path).
 *  5. Update generated_report to "ready" with file_path + expires_at.
 *  6. On error, update to "failed" with the error message.
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use NotifiesResidents;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Extra recipient user ids to notify when the report is ready (scheduled-report
     * recipients). The requesting user is always notified separately.
     *
     * $fromSchedule marks runs fired by reports:run-scheduled — §15.8 mandates
     * those completions go out by EMAIL (with the download link) on top of the
     * in-app/push record.
     *
     * @param  array<int, string>  $notifyUserIds
     */
    public function __construct(
        private readonly GeneratedReport $generatedReport,
        private readonly array $notifyUserIds = [],
        private readonly bool $fromSchedule = false,
    ) {}

    public function handle(ReportFormatter $formatter): void
    {
        $report = $this->generatedReport->fresh();

        if (! $report) {
            return;
        }

        try {
            $report->status = GeneratedReport::STATUS_PROCESSING;
            $report->save();

            $key = $report->report_key;
            $format = $report->format;
            $config = $report->config ?? [];

            $data = $this->buildData($key, $config);

            if ($data === null) {
                throw new \InvalidArgumentException("Reporte '{$key}' no reconocido.");
            }

            $filePath = $this->export($report, $formatter, $key, $format, $data);

            $report->status = GeneratedReport::STATUS_READY;
            $report->file_path = $filePath;
            $report->expires_at = now()->addDays(90);
            $report->error = null;
            $report->save();

            $this->notifyReady($report);

        } catch (\Throwable $e) {
            Log::error('[GenerateReportJob] Report generation failed', [
                'generated_report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            $report->status = GeneratedReport::STATUS_FAILED;
            $report->error = $e->getMessage();
            $report->save();

            $this->notifyFailed($report);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function buildData(string $key, array $config): ?array
    {
        return app(ReportRegistry::class)->build($key, $config);
    }

    /**
     * Notify the requesting user (and any configured scheduled-report recipients)
     * that the report is ready to download. Safe: NotifiesResidents never throws.
     *
     * reports-11 / §15.8: scheduled runs ALSO email the recipients the download
     * link; ad-hoc async requests keep the in-app/push record only.
     */
    private function notifyReady(GeneratedReport $report): void
    {
        $userIds = $this->recipientUserIds($report);

        if ($userIds === []) {
            return;
        }

        $this->notifyUsers(
            $userIds,
            'Reporte listo',
            'Tu reporte "'.$report->report_key.'" ya está disponible para descargar.',
            [
                'click_action' => '/reports',
                'generated_report_id' => $report->id,
                'download_url' => url("/api/reports/generated/{$report->id}/download"),
                'category' => NotificationCategory::REPORTS,
            ],
            channels: $this->fromSchedule ? ['log', 'resend_email'] : ['log'],
        );
    }

    /**
     * reports-11 / §15.8 "Falla en generación → Push a quien lo generó": tell the
     * owner (and scheduled recipients) in-app + email instead of failing silently.
     */
    private function notifyFailed(GeneratedReport $report): void
    {
        $userIds = $this->recipientUserIds($report);

        if ($userIds === []) {
            return;
        }

        $this->notifyUsers(
            $userIds,
            'Falla en generación de reporte',
            'Tu reporte "'.$report->report_key.'" no pudo generarse. Intenta de nuevo o contacta al administrador.',
            [
                'click_action' => '/reports',
                'generated_report_id' => $report->id,
                'category' => NotificationCategory::REPORTS,
            ],
            channels: ['log', 'resend_email'],
        );
    }

    /**
     * Owner + configured scheduled-report recipients, deduplicated.
     *
     * @return array<int, string>
     */
    private function recipientUserIds(GeneratedReport $report): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $this->notifyUserIds,
            [$report->generated_by_user_id],
        ))));
    }

    /**
     * Export report data to the chosen format and persist via Storage.
     * Returns the stored file path (relative to the default disk).
     *
     * @param  array<string, mixed>  $data
     */
    private function export(
        GeneratedReport $report,
        ReportFormatter $formatter,
        string $key,
        string $format,
        array $data
    ): string {
        $tenantId = $report->tenant_id ?? 'central';
        $dir = "reports/{$tenantId}";
        $filename = "{$key}_".now()->format('Ymd_His').'_'.Str::random(6);

        if ($format === 'pdf') {
            $pdfContent = $formatter->pdfContent($key, $data);
            $path = "{$dir}/{$filename}.pdf";
            Storage::put($path, $pdfContent);

            return $path;
        }

        if ($format === 'excel') {
            [$headers, $rows] = $formatter->tableData($key, $data);
            $export = new ReportExport($rows, $headers, $key);
            $path = "{$dir}/{$filename}.xlsx";
            Excel::store($export, $path);

            return $path;
        }

        if ($format === 'csv') {
            [$headers, $rows] = $formatter->tableData($key, $data);
            $export = new ReportExport($rows, $headers, $key);
            $path = "{$dir}/{$filename}.csv";
            Excel::store($export, $path, null, ExcelFormat::CSV);

            return $path;
        }

        // json: store as JSON file
        $path = "{$dir}/{$filename}.json";
        Storage::put($path, json_encode(['report_key' => $key, 'generated_at' => now(), 'data' => $data]));

        return $path;
    }
}
