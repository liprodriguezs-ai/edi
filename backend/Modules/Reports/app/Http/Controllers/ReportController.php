<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Housing\Models\UnitUser;
use Modules\Reports\Jobs\GenerateReportJob;
use Modules\Reports\Models\GeneratedReport;
use Modules\Reports\Services\ReportBoardPublisher;
use Modules\Reports\Services\ReportFormatter;
use Modules\Reports\Services\ReportRegistry;
use Modules\Reports\Services\ReportService;
use QCH\Core\Support\TenantMoney;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportController — exposes READ-ONLY report aggregations.
 *
 * All routes require auth:sanctum + initializeTenancy.
 *
 * Reports expose tenant-wide financials/audit data, so every action is gated
 * behind the `read.report` ability — board/admin only. This plain controller
 * cannot extend the Orion Core controller (no $model), so it replicates the
 * exact token-ability gate used by TraitController::authorize() / VerifyPermission.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly ReportFormatter $formatter,
        private readonly ReportRegistry $registry,
    ) {}

    /**
     * Ensure the current token carries `read.report` (or wildcard).
     * Mirrors QCH\Core\Http\Controllers\TraitController::authorize().
     *
     * @throws AuthorizationException
     */
    private function authorizeReport(): void
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user || (! $user->tokenCan('read.report') && ! $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }

    /**
     * Authorize a generate() request for the given report key.
     *
     * Default: board/admin only (read.report). Exception: a resident may generate
     * `unit_statement` for a unit they belong to (unit_user membership) WITHOUT
     * read.report. `staff_report` (salaries) always requires read.report.
     *
     * @throws AuthorizationException
     */
    /**
     * §15.2 (reports-09) — the Comité de Vigilancia audits finances only: even
     * with read.report it may generate exclusively the financial report keys.
     */
    private const OVERSIGHT_ALLOWED_KEYS = [
        'income_statement',
        'cash_flow',
        'expenses_by_category',
        'annual_fiscal',
        'account_balances',
    ];

    private function authorizeGenerate(string $key, Request $request): void
    {
        $user = auth()->guard('sanctum')->user();

        if ($user && ($user->tokenCan('read.report') || $user->tokenCan('*'))) {
            if ($this->isOversightOnlyActor($user) && ! in_array($key, self::OVERSIGHT_ALLOWED_KEYS, true)) {
                throw new AuthorizationException('El Comité de Vigilancia solo puede generar reportes financieros.');
            }

            return;
        }

        // Resident own-statement path (C01) — only for unit_statement of an owned unit.
        if ($key === 'unit_statement' && $user) {
            $unitId = (string) $request->input('unit_id', '');

            if ($unitId !== '' && $this->userBelongsToUnit($user->id, $unitId)) {
                return;
            }
        }

        throw new AuthorizationException('Unauthorized action.');
    }

    /**
     * True when oversight_committee is the user's highest role — someone who is
     * ALSO admin/president/treasurer keeps the full catalog.
     */
    private function isOversightOnlyActor($user): bool
    {
        $roleNames = $user->roles->pluck('name');

        return $roleNames->contains('oversight_committee')
            && $roleNames->every(
                static fn (string $name): bool => in_array($name, ['oversight_committee', 'resident', 'renter'], true)
            );
    }

    /**
     * True when the user has an active unit_user membership for the unit.
     */
    private function userBelongsToUnit(string $userId, string $unitId): bool
    {
        return UnitUser::query()
            ->where('unit_id', $unitId)
            ->where('user_id', $userId)
            ->whereNull('end_date')
            ->exists();
    }

    /**
     * Units with overdue charges.
     *
     * total_overdue_amount wraps as Money at BOTH levels (per-unit row and the
     * top-level total); units[] are plain arrays (not hydrated models), so the
     * collection is re-mapped here rather than relying on a model cast.
     */
    public function delinquency(): JsonResponse
    {
        $this->authorizeReport();

        $report = $this->reportService->delinquency();

        return response()->json([
            'units' => $report['units']->map(fn (array $row): array => [
                'unit_id' => $row['unit_id'],
                'unit_number' => $row['unit_number'],
                'unit_full_identifier' => $row['unit_full_identifier'],
                'overdue_charges' => $row['overdue_charges'],
                'total_overdue_amount' => TenantMoney::make($row['total_overdue_amount']),
                'oldest_due_date' => $row['oldest_due_date'],
            ]),
            'total_overdue_amount' => TenantMoney::make($report['total_overdue_amount']),
            'unit_count' => $report['unit_count'],
        ]);
    }

    /**
     * Income statement for a period.
     *
     * payments/expenses are hydrated models — their own money fields already
     * serialize as Money via the tenant-aware casts, so only the 3 aggregated
     * scalars are wrapped here.
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorizeReport();

        $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $report = $this->reportService->incomeStatement(
            $request->input('period_start'),
            $request->input('period_end')
        );

        return response()->json([
            'period_start' => $report['period_start'],
            'period_end' => $report['period_end'],
            'total_income' => TenantMoney::make($report['total_income']),
            'total_expenses' => TenantMoney::make($report['total_expenses']),
            'net' => TenantMoney::make($report['net']),
            'payments' => $report['payments'],
            'expenses' => $report['expenses'],
        ]);
    }

    /**
     * Current bank account balances.
     */
    public function accountBalances(): JsonResponse
    {
        $this->authorizeReport();

        return response()->json(['data' => $this->reportService->accountBalances()]);
    }

    /**
     * Audit log (read-only from the audits table).
     *
     * reports-04 — §15.2 denies the audit trail to the treasurer: read.report
     * alone is NOT enough here; the caller must also hold read.audit.
     */
    public function auditLog(Request $request): JsonResponse
    {
        $this->authorizeReport();

        $user = auth()->guard('sanctum')->user();
        if (! $user || (! $user->tokenCan('read.audit') && ! $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $filters = $request->only(['user_id', 'auditable_type', 'from', 'to']);
        $perPage = (int) $request->input('per_page', 50);

        return response()->json($this->reportService->auditLog($filters, $perPage));
    }

    /**
     * Collection progress for a specific charge batch.
     */
    public function collectionProgress(Request $request): JsonResponse
    {
        $this->authorizeReport();

        $request->validate(['batch_id' => ['required', 'uuid']]);

        $report = $this->reportService->collectionProgress($request->input('batch_id'));

        return response()->json([
            'batch_id' => $report['batch_id'],
            'total_charges' => $report['total_charges'],
            'paid_count' => $report['paid_count'],
            'overdue_count' => $report['overdue_count'],
            'pending_count' => $report['pending_count'],
            'total_expected' => TenantMoney::make($report['total_expected']),
            'total_collected' => TenantMoney::make($report['total_collected']),
            'progress_pct' => $report['progress_pct'],
        ]);
    }

    /**
     * On-demand generate endpoint.
     *
     * Supports ?format=json|pdf|excel (default json) and ?async=true.
     *
     * - format=json  → returns the aggregated data inline (original behaviour).
     *   Money fields stay RAW minor-unit integers here — intentional parity with
     *   the PDF/CSV/XLSX exports below (all four read the SAME
     *   registry->cachedBuild() payload; ReportService/ReportRegistry/ReportFormatter
     *   are untouched by the tenant-currency change). This is a DIFFERENT endpoint
     *   from delinquency()/incomeStatement()/collectionProgress() above, which DO
     *   wrap money as Money objects at their own JSON boundary.
     * - format=pdf   → streams a PDF download.
     * - format=excel → streams an xlsx download.
     * - async=true   → dispatches GenerateReportJob, returns the generated_report id.
     */
    public function generate(
        Request $request,
        string $key
    ): JsonResponse|Response|BinaryFileResponse|StreamedResponse {
        if (! ReportRegistry::isAllowed($key)) {
            return response()->json(['message' => "Reporte '{$key}' no encontrado."], 404);
        }

        $this->authorizeGenerate($key, $request);

        $format = $request->input('format', 'json');
        $async = $request->boolean('async', false);

        $validFormats = ['json', 'pdf', 'excel', 'csv'];
        if (! in_array($format, $validFormats, true)) {
            return response()->json(['message' => "Formato '{$format}' no soportado. Use: ".implode(', ', $validFormats)], 422);
        }

        // --- Async mode: dispatch job, return generated_report record ---
        if ($async) {
            $config = $request->except(['format', 'async']);
            $generatedReport = GeneratedReport::create([
                'report_key' => $key,
                'format' => $format,
                'config' => $config,
                'status' => GeneratedReport::STATUS_PENDING,
                'generated_by_user_id' => auth()->id(),
                'expires_at' => now()->addDays(90),
            ]);

            GenerateReportJob::dispatch($generatedReport);

            return response()->json(['data' => $generatedReport], 202);
        }

        // --- Sync mode: build data immediately (5-min cached per tenant+params) ---
        $data = $this->registry->cachedBuild($key, $request->except(['format', 'async']));

        if ($data === null) {
            return response()->json(['message' => "Reporte '{$key}' no encontrado."], 404);
        }

        return match ($format) {
            'pdf' => $this->formatter->pdf($key, $data),
            'excel' => $this->formatter->excel($key, $data),
            'csv' => $this->formatter->csv($key, $data),
            default => response()->json(['report_key' => $key, 'generated_at' => now(), 'data' => $data]),
        };
    }

    /**
     * List the tenant's generated reports (async, ≤90 days old).
     *
     * reports-05: a user WITHOUT read.report (e.g. a resident who generated their
     * own unit_statement async) only sees the reports THEY requested.
     */
    public function listGenerated(): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $query = GeneratedReport::where('expires_at', '>', now())
            ->orderByDesc('created_at');

        if (! $user->tokenCan('read.report') && ! $user->tokenCan('*')) {
            $query->where('generated_by_user_id', $user->id);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Stream/download a generated report file.
     *
     * reports-05: ownership-based access — the user who requested the report can
     * always download it (the resident async flow dead-ended in 403 before);
     * read.report keeps board/admin access to every tenant report. TenantScope
     * already 404s reports from other tenants.
     */
    public function downloadGenerated(string $id): BinaryFileResponse|JsonResponse
    {
        $report = GeneratedReport::findOrFail($id);

        $user = auth()->guard('sanctum')->user();
        $hasReadReport = $user && ($user->tokenCan('read.report') || $user->tokenCan('*'));
        $isOwner = $user && $report->generated_by_user_id === $user->id;

        if (! $hasReadReport && ! $isOwner) {
            throw new AuthorizationException('Unauthorized action.');
        }

        if ($report->status !== GeneratedReport::STATUS_READY) {
            return response()->json(['message' => 'El reporte aún no está listo.'], 409);
        }

        if (! $report->file_path || ! Storage::exists($report->file_path)) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        return response()->download(Storage::path($report->file_path));
    }

    /**
     * POST /reports/{key}/publish-to-board — §15.4.2 "Publicar en pizarra".
     *
     * Generates the report as PDF and publishes it as a cluster-wide Announcement
     * with the file attached (transparency). Requires BOTH read.report (seeing the
     * data) and create.announcement (publishing to the board) — president,
     * administrator and treasurer hold both per the seeded matrix.
     */
    public function publishToBoard(Request $request, string $key): JsonResponse
    {
        if (! ReportRegistry::isAllowed($key)) {
            return response()->json(['message' => "Reporte '{$key}' no encontrado."], 404);
        }

        $user = auth()->guard('sanctum')->user();

        $canPublish = $user
            && ($user->tokenCan('read.report') || $user->tokenCan('*'))
            && ($user->tokenCan('create.announcement') || $user->tokenCan('*'));

        if (! $canPublish) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $request->validate(['title' => ['sometimes', 'string', 'max:255']]);

        $config = $request->except(['title']);
        $data = $this->registry->build($key, $config);

        if ($data === null) {
            return response()->json(['message' => "Reporte '{$key}' no encontrado."], 404);
        }

        $announcement = app(ReportBoardPublisher::class)->publish(
            $key,
            $this->formatter->pdfContent($key, $data),
            $this->formatter->humanTitle($key),
            (string) $user->id,
            $request->input('title'),
        );

        return response()->json(['data' => $announcement], 201);
    }
}
