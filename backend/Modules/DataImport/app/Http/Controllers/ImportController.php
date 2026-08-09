<?php

namespace Modules\DataImport\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\DataImport\Http\Requests\ImportExecuteRequest;
use Modules\DataImport\Http\Requests\ImportValidateRequest;
use Modules\DataImport\Jobs\RunImportJob;
use Modules\DataImport\Models\ImportRun;
use Modules\DataImport\Services\ImportRunner;
use Modules\DataImport\Services\TemplateGenerator;
use Modules\DataImport\Services\WorkbookReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ImportController — custom (non-Orion) controller for payment import endpoints.
 *
 * Routes are gated by middleware: auth:sanctum + rejectRefreshToken + authActive
 * + initializeTenancy. Permission checks use `read.data_import` / `create.data_import`
 * (verified by the token ability system; see authorize() helper below).
 *
 * Four custom endpoints (not Orion resource):
 *   GET  /import/template
 *   POST /import/validate
 *   POST /import/execute
 *   GET  /import/{id}/status
 */
class ImportController extends Controller
{
    /**
     * Ensure the current token carries the given ability (or wildcard).
     *
     * This plain controller cannot extend the Orion Core controller (no $model),
     * so it replicates the exact token-ability gate used by
     * QCH\Core\Http\Controllers\TraitController::authorize() — same pattern as
     * ReportController::authorizeReport().
     *
     * @throws AuthorizationException
     */
    private function authorize(string $ability): void
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user || (! $user->tokenCan($ability) && ! $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }

    /**
     * GET /import/template
     * Download the .xlsx template scoped to the current tenant.
     */
    public function template(TemplateGenerator $generator): StreamedResponse|BinaryFileResponse
    {
        $this->authorize('read.data_import');

        return $generator->download();
    }

    /**
     * POST /import/validate
     *
     * Parses the uploaded file and runs a synchronous dry-run validation.
     * ZERO DB writes. Returns a preview payload with counts and per-row issues.
     *
     * The read is BOUNDED to config('dataimport.validate_row_cap') data rows
     * (default 1000) via WorkbookReader::parsePreview() — see plan 059. A
     * .xlsx is a zip; without a cap, a crafted/huge file would materialize
     * hundreds of MB of PHP-array cells on a shared Octane worker in-request.
     * EXECUTE (POST /import/execute) is a separate, queued + chunked path
     * and is NOT affected by this cap — it stays full-fidelity.
     *
     * HTTP 200 when blocked === 0 (warnings are allowed to proceed).
     * HTTP 422 when blocked > 0 — errors surfaced as payload.errors keyed
     *   rows.<n>.<col> to match the ApiError.payload.errors contract on the FE.
     */
    public function validateImport(ImportValidateRequest $request, WorkbookReader $reader, ImportRunner $runner): JsonResponse
    {
        $this->authorize('create.data_import');

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $rowCap = (int) config('dataimport.validate_row_cap', 1000);

        try {
            $preview = $reader->parsePreview($filePath, $rowCap);
        } catch (\InvalidArgumentException $e) {
            // A malformed workbook (missing "PAGOS" sheet or expected headers) is a
            // user input error, not a server fault — surface it as 422 instead of
            // letting it bubble up to the global handler as a 500. The reader's
            // message is already user-facing and tenant-safe.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result = $runner->validate($preview['rows']);

        if ($preview['truncated']) {
            // The admin isn't misled: 'total'/'rows' below only cover the
            // first $rowCap rows, not the whole file. EXECUTE (which is not
            // bounded) remains the source of truth for full validation.
            $result['truncated'] = true;
            $result['row_cap'] = $rowCap;
            $result['preview_message'] = "Se validaron las primeras {$rowCap} filas del archivo. Importa el archivo completo para procesar el resto.";
        }

        if ($result['blocked'] > 0) {
            // Build errors keyed by rows.<n>.<col> for FE ApiError compatibility.
            $errors = [];
            foreach ($result['rows'] as $issue) {
                if ($issue['severity'] === 'error') {
                    $key = "rows.{$issue['row']}.{$issue['column']}";
                    $errors[$key][] = $issue['message'];
                }
            }

            return response()->json(
                array_merge($result, ['message' => 'El archivo contiene errores que impiden la importación.', 'errors' => $errors]),
                422
            );
        }

        return response()->json($result);
    }

    /**
     * POST /import/execute
     *
     * Stores the uploaded file, creates an ImportRun, and dispatches RunImportJob.
     * Returns the import_run_id immediately (before processing completes).
     */
    public function executeImport(ImportExecuteRequest $request): JsonResponse
    {
        $this->authorize('create.data_import');

        $file = $request->file('file');

        // Store file in tenant-namespaced path for the job to read
        $tenantId = tenant('id');
        $filename = 'import_'.Str::uuid().'.'.$file->getClientOriginalExtension();
        $storedPath = $file->storeAs("imports/{$tenantId}", $filename);

        $run = ImportRun::create([
            'status' => ImportRun::STATUS_PENDING,
            'phase' => ImportRun::PHASE_READING_FILE,
            'file_path' => $storedPath,
            'progress' => ['processed' => 0, 'total' => 0, 'phase' => ImportRun::PHASE_READING_FILE],
            'report' => null,
            'error' => null,
        ]);

        // Dispatch the coordinator with the run id (tiny payload across K chunk jobs)
        // and the absolute file path. The coordinator parses once, caches the rows,
        // and dispatches the Bus::batch of chunk jobs + the finalizer.
        RunImportJob::dispatch($run->id, Storage::path($storedPath))
            ->onQueue(RunImportJob::QUEUE);

        return response()->json(['import_run_id' => $run->id]);
    }

    /**
     * GET /import/{id}/status
     * Poll the status and progress of an ImportRun.
     * BelongsToTenant auto-scopes the query to the current tenant.
     */
    public function status(string $id): JsonResponse
    {
        $this->authorize('read.data_import');

        $run = ImportRun::findOrFail($id);

        return response()->json([
            'status' => $run->status,
            'phase' => $run->phase,
            'progress' => $run->progress,
            'report' => $run->report,
            'error' => $run->error,
        ]);
    }
}
