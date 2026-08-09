<?php

declare(strict_types=1);

namespace Modules\Package\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Package\Models\Package;
use Modules\Package\Services\PackageReportService;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Http\Controllers\ReportExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only package report endpoints.
 *
 * Authorization:
 *   aging + export → read.report_package
 *   pendingCount   → read.package (visible to any package-reader for widget)
 *
 * Business logic lives in PackageReportService — controller is thin.
 */
class PackageReportController extends Controller
{
    public function __construct(private readonly PackageReportService $service) {}

    /**
     * GET /api/package/report/aging
     * Permission: read.report_package
     *
     * Returns aging buckets (d0_2, d3_7, d7_plus) for all READY packages.
     * All calculations use the tenant's configured timezone.
     */
    public function aging(Request $request): JsonResponse
    {
        $this->authorize('read.report_package', Package::class);

        return response()->json($this->service->aging());
    }

    /**
     * GET /api/package/report/aging/export
     * Permission: read.report_package
     *
     * Downloads the aging data as an xlsx file using the shared ReportExport framework.
     * Rows are sorted by days_pending descending (oldest first).
     * Filename: paquetes-pendientes-{YYYY-MM-DD}.xlsx
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('read.report_package', Package::class);

        // Aggregation (READY load, per-package day computation, sort) lives in the
        // service so the day/timezone semantics stay in a single place (shared with
        // aging()). The controller only orchestrates the ReportExport wiring.
        $rows = $this->service->agingExportRows();

        $columns = $this->reportColumns();

        $export = new ReportExport;
        $export->setTitle('Paquetes Pendientes de Retiro');
        $export->setHeadings(array_keys($columns));
        $export->setMap(array_values($columns));
        $export->setCollection($rows);

        $filename = 'paquetes-pendientes-'.date('Y-m-d').'.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * GET /api/package/report/pending-count
     * Permission: create.package (guards) OR read.report_package (board) OR * (super-admin).
     *
     * Lightweight two-COUNT aggregation for the dashboard widget.
     * Residents and renters (read.package only) are excluded: the widget shows
     * cluster-wide counts that only staff and board roles should see.
     * Does NOT load package data — only COUNT queries.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $user = $request->user();

        // Mirror scopeToResidentUnitsIfNeeded's staff/board check.
        if (
            ! $user?->tokenCan('create.package') &&
            ! $user?->tokenCan('read.report_package') &&
            ! $user?->tokenCan('*')
        ) {
            abort(403, 'Acceso no autorizado.');
        }

        return response()->json($this->service->pendingCount());
    }

    /**
     * Column definitions for the aging Excel export.
     *
     * Wires this controller into the shared ReportController/ReportExport framework
     * (same interface used by Collaborator, Collections, Treasury, etc.).
     * Keys are the Spanish column headers; values are callables that receive a row object.
     *
     * @return array<string, callable(\stdClass): mixed>
     */
    public function reportColumns(): array
    {
        return [
            'Unidad' => static fn (\stdClass $row): string => (string) ($row->unit_full_identifier ?? ''),
            'Transportista' => static fn (\stdClass $row): string => (string) ($row->carrier ?? ''),
            'Número de seguimiento' => static fn (\stdClass $row): string => (string) ($row->tracking_number ?? ''),
            'Descripción' => static fn (\stdClass $row): string => (string) ($row->description ?? ''),
            'Ubicación de almacenamiento' => static fn (\stdClass $row): string => (string) ($row->storage_location ?? ''),
            'Recibido el' => static fn (\stdClass $row): string => (string) ($row->received_at_label ?? ''),
            'Días pendiente' => static fn (\stdClass $row): int => (int) ($row->days_pending ?? 0),
            'Estado' => static fn (\stdClass $row): string => (string) ($row->status_label ?? ''),
        ];
    }
}
