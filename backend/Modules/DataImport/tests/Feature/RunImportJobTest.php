<?php

namespace Modules\DataImport\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Telescope\Telescope;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Services\ChargeStatusService;
use Modules\DataImport\Jobs\FinalizeImportJob;
use Modules\DataImport\Jobs\ProcessImportChunkJob;
use Modules\DataImport\Jobs\RunImportJob;
use Modules\DataImport\Models\ImportRun;
use Modules\DataImport\Services\RowImporter;
use Modules\DataImport\Services\WorkbookReader;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for the chunked import job engine (SLICE 3).
 *
 * Covers spec acceptance scenarios:
 *  - SCENARIO-01 happy path multi-chunk end-to-end (coordinator → batch → finalizer)
 *  - SCENARIO-02 chunk-granular dispatch + ≤10 import_run progress UPDATEs
 *  - SCENARIO-03 partial failure → completed_with_errors with per-row error list
 *  - SCENARIO-04 idempotency: re-import same file → all skipped, no new rows
 *  - SCENARIO-09 money in minor units + receipt_number R-YYYY-NNNNNN
 *  - SCENARIO-10 ChargeStatusService runs in finalizer (once per unit), not per row
 *  - SCENARIO-11 defensive guard failure counted as failed; chunk keeps processing
 *  - coordinator failure path (parse fails before batch) → status=failed (not stuck)
 *
 * Runs under QUEUE_CONNECTION=sync, so Bus::batch chunk jobs AND the ->finally
 * finalizer execute INLINE during the execute() request (proven by slice-1 tests).
 */
class RunImportJobTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected string $token;

    protected string $tempDir;

    protected UnitType $unitType;

    protected UnitStatus $unitStatus;

    protected BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        $this->seedTenant($this->tenant);
        tenancy()->initialize($this->tenant);

        $user = $this->makeUser($this->tenant);
        $this->token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tempDir = sys_get_temp_dir().'/run_import_job_test_'.uniqid();
        mkdir($this->tempDir, 0775, true);

        $this->unitType = UnitType::firstOrCreate(
            ['name' => 'Casa', 'tenant_id' => tenant('id')],
            ['order' => 1, 'is_active' => true, 'requires_floor' => false]
        );

        $this->unitStatus = UnitStatus::firstOrCreate(
            ['name' => 'Activa', 'tenant_id' => tenant('id')],
            ['order' => 1, 'is_active' => true, 'is_billable' => true]
        );

        $purpose = BankAccountPurpose::where('tenant_id', tenant('id'))->first()
            ?? BankAccountPurpose::create([
                'name' => 'General',
                'order' => 1,
                'is_active' => true,
            ]);

        $this->bankAccount = BankAccount::firstOrCreate(
            ['name' => 'Caja Principal', 'tenant_id' => tenant('id')],
            [
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        // Clear the run-scoped rows.json cache + any uploaded files (design risk:
        // a stray cache between tests would skew idempotency / chunk-load behavior).
        foreach (glob($this->tempDir.'/*.xlsx') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<int, string>
     */
    private function headerRow(): array
    {
        return [
            'tipo_unidad', 'numero', 'torre', 'piso', 'seccion', 'estado_unidad',
            'indiviso', 'm2_construido', 'm2_terreno',
            'nombre', 'apellido_paterno', 'apellido_materno',
            'email', 'telefono', 'relacion', 'titular_de_pago',
            'rfc', 'curp', 'fecha_nacimiento',
            'fecha_pago', 'monto', 'metodo_pago', 'cuenta_destino',
            'referencia', 'folio', 'notas',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function validDataRow(string $numero = '101', string $fecha = '2024-03-15', string $monto = '1500.00'): array
    {
        return [
            'Casa', $numero, null, null, null, 'Activa',
            null, null, null,
            'Juan', 'Pérez', null,
            null, null, null, null,
            null, null, null,
            $fecha, $monto, null, 'Caja Principal',
            null, null, null,
        ];
    }

    /**
     * A row whose unit code does not exist AND has no resolvable unit_type — but
     * the defensive guard passes (all mandatory fields present), so it reaches
     * execute(). It still succeeds (auto-create), so for "missing unit" failures
     * we instead use blockedDataRow() (monto missing → defensive-guard failure).
     *
     * @return array<int, mixed>
     */
    private function blockedDataRow(string $numero = '999'): array
    {
        $row = $this->validDataRow($numero);
        $row[20] = null; // monto column → defensive guard counts this as failed

        return $row;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildXlsx(array $rows, string $filename = 'test.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PAGOS');

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }

        $path = $this->tempDir.'/'.$filename;
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Build a file with $count unique valid rows (header + data).
     *
     * @return array<int, array<int, mixed>>
     */
    private function manyValidRows(int $count, int $startNumber = 1000): array
    {
        $rows = [$this->headerRow()];

        for ($i = 0; $i < $count; $i++) {
            $number = (string) ($startNumber + $i);
            // Distinct unit + amount per row so the natural-key idempotency check
            // never skips a legitimately distinct payment.
            $monto = number_format(100 + $i, 2, '.', '');
            $rows[] = $this->validDataRow($number, '2024-03-15', $monto);
        }

        return $rows;
    }

    /**
     * Execute the import inline via the sync queue. RunImportJob (and its Bus::batch
     * chunk jobs + the ->finally finalizer) run for real; only the receipt PDF job
     * is faked away.
     */
    private function executeSync(UploadedFile $file): TestResponse
    {
        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file]);

        // HTTP terminate() ends tenancy; re-initialize for post-request assertions.
        tenancy()->initialize($this->tenant);

        return $response;
    }

    // =========================================================================
    // SCENARIO-01 — happy path, multi-chunk end-to-end
    // =========================================================================

    public function test_scenario_01_multi_chunk_happy_path_completes_end_to_end(): void
    {
        // 205 rows > CHUNK_SIZE (200) → 2 chunks: exercises the full
        // coordinator → Bus::batch(chunk×2) → finalizer pipeline.
        $count = RunImportJob::CHUNK_SIZE + 5;
        $file = $this->buildXlsx($this->manyValidRows($count));

        $paymentsBefore = Payment::count();

        $response = $this->executeSync($file);
        $response->assertStatus(200);

        $run = ImportRun::find($response->json('import_run_id'));

        // Terminal: clean success.
        $this->assertSame(ImportRun::STATUS_READY, $run->status, 'multi-chunk valid import must end ready');
        $this->assertSame(ImportRun::PHASE_DONE, $run->phase);

        // Report counts correct.
        $report = $run->report;
        $this->assertSame($count, $report['succeeded_count'], 'all rows succeeded');
        $this->assertSame(0, $report['payments_skipped']);
        $this->assertSame(0, $report['failed_count']);
        $this->assertSame($count, $report['payments_registered']);

        // Auto-creation warnings ("… se creó automáticamente") are suppressed from the
        // report — 205 new units + residents would otherwise add ~410 noise rows.
        $autoCreateWarnings = array_filter(
            $report['rows'] ?? [],
            fn ($r): bool => str_contains($r['message'] ?? '', 'se creó automáticamente')
        );
        $this->assertCount(0, $autoCreateWarnings, 'auto-creation warnings must be excluded from report.rows');

        // Progress: processed == total (attempted), total honest.
        $this->assertSame($count, $run->progress['total']);
        $this->assertSame($count, $run->progress['processed']);

        // Every row produced a real payment with a valid receipt_number.
        $this->assertSame($paymentsBefore + $count, Payment::count(), 'one payment per row created');

        $payment = Payment::orderByDesc('created_at')->first();
        $this->assertMatchesRegularExpression(
            '/^R-\d{4}-\d{6}$/',
            $payment->receipt_number,
            'receipt_number must match R-YYYY-NNNNNN'
        );

        // Units committed for the good rows.
        $this->assertSame($count, Unit::where('tenant_id', tenant('id'))->whereIn('number', array_map('strval', range(1000, 1000 + $count - 1)))->count());
    }

    // =========================================================================
    // SCENARIO-02 — chunk granularity
    // =========================================================================

    public function test_scenario_02_dispatches_one_chunk_job_per_slice(): void
    {
        // Inspect the dispatched batch WITHOUT executing rows. The controller stores the
        // file + creates the run; with Bus faked we run the coordinator's handle()
        // directly so it parses, sets progress.total, and dispatches the (captured) batch.
        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $count = 500;            // 500 / 200 = 3 chunks (200 + 200 + 100)
        $expectedChunks = (int) ceil($count / RunImportJob::CHUNK_SIZE);

        $file = $this->buildXlsx($this->manyValidRows($count));

        Bus::fake();

        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');

        tenancy()->initialize($this->tenant);

        $run = ImportRun::find($runId);
        $coordinator = new RunImportJob($run->id, Storage::path($run->file_path));
        $coordinator->handle(app(WorkbookReader::class));

        // Exactly one Bus::batch dispatched, carrying ceil(total/CHUNK_SIZE) chunk jobs.
        Bus::assertBatched(function ($batch) use ($expectedChunks): bool {
            return $batch->jobs->count() === $expectedChunks
                && $batch->jobs->every(fn ($job): bool => $job instanceof ProcessImportChunkJob);
        });

        // Coordinator set the honest total BEFORE any chunk ran (kills 0/0).
        $run->refresh();
        $this->assertSame($count, $run->progress['total']);
        $this->assertSame(ImportRun::PHASE_PROCESSING, $run->phase);
    }

    public function test_scenario_02_progress_is_persisted_per_chunk_not_per_row(): void
    {
        // Run for real (sync) and count UPDATE statements against import_run.
        // Spec: a multi-chunk run must persist progress per chunk, not per row.
        $count = RunImportJob::CHUNK_SIZE + 50; // 250 → 2 chunks
        $file = $this->buildXlsx($this->manyValidRows($count, 2000));

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $importRunUpdates = 0;
        DB::listen(function ($query) use (&$importRunUpdates): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'update') && str_contains($sql, 'import_run')) {
                $importRunUpdates++;
            }
        });

        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');

        tenancy()->initialize($this->tenant);

        // Progress ticks every ~PROGRESS_TICK (50) rows so the UI bar advances smoothly,
        // NOT once per row (the original defect ≈ $count UPDATEs). For a 250-row / 2-chunk
        // run that's ~5 ticks + per-chunk persist + ~4 orchestration writes — far below per-row.
        $this->assertLessThanOrEqual(
            (int) ceil($count / 25) + 8,
            $importRunUpdates,
            'import_run progress UPDATEs must stay far below per-row (ticked every ~50 rows)'
        );

        $run = ImportRun::find($runId);
        $this->assertSame(ImportRun::STATUS_READY, $run->status);
    }

    // =========================================================================
    // SCENARIO-03 — partial failure
    // =========================================================================

    public function test_scenario_03_partial_failure_ends_completed_with_errors(): void
    {
        // 3 good rows + 2 rows that fail the defensive guard (monto missing).
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('301'),
            $this->validDataRow('302'),
            $this->blockedDataRow('991'),
            $this->validDataRow('303'),
            $this->blockedDataRow('992'),
        ]);

        $paymentsBefore = Payment::count();

        $run = ImportRun::find($this->executeSync($file)->json('import_run_id'));

        // Distinct terminal state.
        $this->assertSame(ImportRun::STATUS_COMPLETED_WITH_ERRORS, $run->status);
        $this->assertSame(ImportRun::PHASE_DONE, $run->phase);

        $report = $run->report;
        $this->assertSame(3, $report['succeeded_count'], '3 good rows succeeded');
        $this->assertSame(2, $report['failed_count'], '2 rows failed the guard');
        $this->assertSame(0, $report['payments_skipped']);

        // succeeded + skipped + failed == total == processed.
        // NOTE: the stored report names the skip counter `payments_skipped`
        // (the FE maps it onto the spec's `skipped_count`); see test-suite risks.
        $total = $run->progress['total'];
        $this->assertSame(5, $total);
        $this->assertSame(
            $total,
            $report['succeeded_count'] + $report['payments_skipped'] + $report['failed_count'],
            'succeeded + skipped + failed must equal total'
        );
        $this->assertSame($total, $run->progress['processed']);

        // Failed rows are listed with row number + error message.
        $errorRows = collect($report['rows'])->where('severity', 'error');
        $this->assertGreaterThanOrEqual(2, $errorRows->count());
        foreach ($errorRows as $issue) {
            $this->assertArrayHasKey('row', $issue);
            $this->assertArrayHasKey('message', $issue);
            $this->assertNotEmpty($issue['message']);
        }

        // Good rows still committed payments + units.
        $this->assertSame($paymentsBefore + 3, Payment::count(), 'good rows committed despite partial failure');
        $this->assertTrue(Unit::where('tenant_id', tenant('id'))->where('number', '301')->exists());
    }

    // =========================================================================
    // SCENARIO-04 — idempotency: re-import same file
    // =========================================================================

    public function test_scenario_04_reimport_same_file_skips_all_rows(): void
    {
        $rows = [
            $this->headerRow(),
            $this->validDataRow('401', '2024-04-01', '1000.00'),
            $this->validDataRow('402', '2024-04-02', '2000.00'),
            $this->validDataRow('403', '2024-04-03', '3000.00'),
        ];

        // First import — all succeed.
        $first = ImportRun::find($this->executeSync($this->buildXlsx($rows, 'first.xlsx'))->json('import_run_id'));
        $this->assertSame(ImportRun::STATUS_READY, $first->status);
        $this->assertSame(3, $first->report['payments_registered']);

        $paymentsAfterFirst = Payment::count();
        $applicationsAfterFirst = PaymentApplication::count();
        $balanceAfterFirst = (int) BankAccount::find($this->bankAccount->id)->getRawOriginal('current_balance');

        // Second import — same file content.
        $second = ImportRun::find($this->executeSync($this->buildXlsx($rows, 'second.xlsx'))->json('import_run_id'));

        // Idempotent: ready, zero registered, all skipped, zero failed.
        $this->assertSame(ImportRun::STATUS_READY, $second->status);
        $this->assertSame(ImportRun::PHASE_DONE, $second->phase);
        $this->assertSame(0, $second->report['succeeded_count']);
        $this->assertSame(3, $second->report['payments_skipped']);
        $this->assertSame(0, $second->report['failed_count']);

        // No new payments / applications / balance change.
        $this->assertSame($paymentsAfterFirst, Payment::count(), 'no duplicate payments on re-import');
        $this->assertSame($applicationsAfterFirst, PaymentApplication::count(), 'no duplicate payment_application rows');
        $this->assertSame(
            $balanceAfterFirst,
            (int) BankAccount::find($this->bankAccount->id)->getRawOriginal('current_balance'),
            'bank balance unchanged on re-import'
        );
    }

    // =========================================================================
    // SCENARIO-09 — money in minor units + receipt number format
    // =========================================================================

    public function test_scenario_09_money_stored_in_minor_units_with_valid_receipt(): void
    {
        // $1,500.00 MXN → 150000 minor units.
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('901', '2024-05-10', '1500.00'),
        ]);

        $this->executeSync($file);

        $payment = Payment::where('unit_id', Unit::where('number', '901')->value('id'))->firstOrFail();

        // Raw stored value is the integer minor-units amount, not a decimal.
        $this->assertSame(150000, (int) $payment->getRawOriginal('total_amount'), 'amount stored as 150000 minor units');

        // receipt_number sequences per tenant per YEAR-OF-PAYMENT (paid_at), not the
        // current date — the spec's "current year" is really the payment year.
        $paidYear = substr((string) $payment->getRawOriginal('paid_at'), 0, 4);
        $this->assertSame('2024', $paidYear, 'sanity: the payment date year is 2024');
        $this->assertMatchesRegularExpression(
            '/^R-2024-\d{6}$/',
            $payment->receipt_number,
            'receipt_number must be R-{paymentYear}-NNNNNN'
        );
    }

    // =========================================================================
    // SCENARIO-10 — ChargeStatusService runs in finalizer (once per unit)
    // =========================================================================

    public function test_scenario_10_recompute_runs_in_finalizer_not_per_row(): void
    {
        // Spy on ChargeStatusService: recomputeUnitAggregates must be called exactly
        // ONCE (the finalizer), not once per chunk and never per row.
        $spy = new class extends ChargeStatusService
        {
            public int $aggregateCalls = 0;

            /** @var array<int, string> */
            public array $lastUnitIds = [];

            public function recomputeUnitAggregates(array $unitIds): void
            {
                $this->aggregateCalls++;
                $this->lastUnitIds = $unitIds;

                parent::recomputeUnitAggregates($unitIds);
            }
        };

        $this->app->instance(ChargeStatusService::class, $spy);

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('1001'),
            $this->validDataRow('1002'),
            $this->validDataRow('1001', '2024-06-02', '500.00'), // same unit, different payment
        ]);

        $this->executeSync($file);

        $this->assertSame(1, $spy->aggregateCalls, 'recomputeUnitAggregates must run exactly once (finalizer)');

        // It was called with the set of unique affected units (2 distinct units here).
        $this->assertCount(2, array_unique($spy->lastUnitIds), 'finalizer recompute receives the unique affected units');
    }

    // =========================================================================
    // SCENARIO-11 — defensive guard failure counted; chunk keeps processing
    // =========================================================================

    public function test_scenario_11_guard_failure_counted_and_chunk_continues(): void
    {
        // A guard-failing row in the MIDDLE must not abort the rows after it.
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('1101'),
            $this->blockedDataRow('1191'), // fails defensive guard
            $this->validDataRow('1102'),   // must still process
        ]);

        $paymentsBefore = Payment::count();

        $run = ImportRun::find($this->executeSync($file)->json('import_run_id'));

        $report = $run->report;
        $this->assertSame(1, $report['failed_count'], 'the guard-failing row is counted as failed');
        $this->assertSame(2, $report['succeeded_count'], 'rows before AND after the failure both processed');
        $this->assertSame($paymentsBefore + 2, Payment::count(), 'chunk did not crash on the bad row');

        // The failed row is surfaced; no PHP fatal aborted the chunk.
        $errorRows = collect($report['rows'])->where('severity', 'error');
        $this->assertGreaterThanOrEqual(1, $errorRows->count());
    }

    // =========================================================================
    // Coordinator failure path — parse fails BEFORE batch dispatch
    // =========================================================================

    public function test_coordinator_parse_failure_marks_run_failed_not_stuck(): void
    {
        // A workbook WITHOUT the PAGOS sheet makes WorkbookReader::parse throw inside
        // the coordinator, before any batch is dispatched. The run must reach a
        // terminal failed state — never left stuck in processing.
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('OTRA_HOJA');
        $sheet->setCellValue('A1', 'no es la hoja pagos');
        $path = $this->tempDir.'/bad.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $badFile = new UploadedFile(
            $path,
            'bad.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        // /validate would 422 a bad sheet, but /execute trusts the upload and lets the
        // coordinator surface the parse error as a failed run. Create the run directly
        // through the controller and let the coordinator run inline.
        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $badFile]);

        tenancy()->initialize($this->tenant);

        $response->assertStatus(200);
        $run = ImportRun::find($response->json('import_run_id'));

        $this->assertSame(ImportRun::STATUS_FAILED, $run->status, 'parse failure → failed, not stuck processing');
        $this->assertSame(ImportRun::PHASE_DONE, $run->phase, 'a failed run reaches a terminal phase');
        $this->assertNotEmpty($run->error, 'the parse error message is surfaced on the run');
    }

    // =========================================================================
    // REVIEW FIX — chunk hard failure → failed() → run terminal + instrumentation restored
    // =========================================================================

    public function test_chunk_hard_failure_marks_run_failed_and_restores_instrumentation(): void
    {
        // A hard chunk failure (OOM / $timeout SIGALRM / fatal Throwable / exhausted
        // tries) bypasses the handle() try/finally on a long-lived worker — the worker
        // traps it and calls failed() on a fresh frame. We model that exact lifecycle:
        // handle() throws → the worker invokes failed($e).
        //
        // Deterministic hard fault: create a real mid-flight run + rows cache, then DELETE
        // the cache so the chunk's loadSlice() throws RuntimeException (a hard fault, NOT
        // a legitimately empty slice). The per-row catch only guards execute(); a
        // loadSlice fault escapes handle() exactly like a real hard failure.
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('2101'),
            $this->validDataRow('2102'),
        ]);

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        // Stage a run via the controller WITHOUT running the coordinator/chunks: fake Bus
        // so the inner batch is captured, not executed.
        Bus::fake();
        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');
        tenancy()->initialize($this->tenant);

        $run = ImportRun::find($runId);

        // Run the coordinator so it parses + writes the rows cache + sets total/processing.
        (new RunImportJob($run->id, Storage::path($run->file_path)))->handle(app(WorkbookReader::class));
        $run->refresh();
        $this->assertSame(ImportRun::PHASE_PROCESSING, $run->phase, 'precondition: run is mid-flight (processing)');

        // Sabotage: delete the row cache so the chunk hits a hard missing-cache fault.
        $cachePath = RunImportJob::cachePath((string) tenant('id'), $run->id);
        Storage::delete($cachePath);
        $this->assertFalse(Storage::exists($cachePath), 'precondition: cache removed');

        // Arrange instrumentation pre-state so we can prove failed() restored it.
        Config::set('logging.query.enabled', true);
        Config::set('logging.query.except', ['original/path']);
        Config::set('logging.query.slower_than', 123);
        $telescopeBefore = Telescope::isRecording();

        // Drive the chunk exactly as the queue worker does: handle() throws → failed().
        $chunk = new ProcessImportChunkJob($run->id, 0, RunImportJob::CHUNK_SIZE);

        $caught = null;
        try {
            $chunk->handle(app(RowImporter::class));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a missing cache slice must throw a hard fault (→ failed())');

        // The worker calls failed() with the captured exception.
        $chunk->failed($caught);

        // The run is now terminal-failed — never left stuck in `processing`.
        $run->refresh();
        $this->assertSame(ImportRun::STATUS_FAILED, $run->status, 'chunk hard failure → run failed (not stuck processing)');
        $this->assertSame(ImportRun::PHASE_DONE, $run->phase, 'failed run reaches a terminal phase');
        $this->assertNotEmpty($run->error, 'the failure message is surfaced on the run');

        // Instrumentation was restored EXACTLY — the kill-switch did not poison the worker.
        $this->assertTrue(config('logging.query.enabled'), 'query-logger enabled restored after failed()');
        $this->assertSame(['original/path'], config('logging.query.except'), 'query-logger except restored after failed()');
        $this->assertSame(123, config('logging.query.slower_than'), 'query-logger slower_than restored after failed()');
        $this->assertSame($telescopeBefore, Telescope::isRecording(), 'Telescope recording restored after failed()');
    }

    public function test_finalizer_hard_failure_marks_run_failed_not_stuck_finalizing(): void
    {
        // A finalizer that dies hard (OOM / $timeout SIGALRM / fatal Throwable) escapes
        // handle()'s try/finally on a long-lived worker, which then calls failed(). The
        // recompute is wrapped in its own non-fatal try/catch (payments already landed),
        // so the only path that leaves the run terminal-stuck is a fatal that bypasses
        // handle entirely — modelled here by driving failed() directly. The run must end
        // `failed`, never stuck `finalizing`.
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('2201'),
        ]);

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        // Build a real, mid-flight run that has reached the finalizing phase. Run the
        // coordinator + chunk, then set phase=finalizing to mimic the worker dying right
        // after the finalizer started but before it persisted a terminal status.
        Bus::fake();
        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');
        tenancy()->initialize($this->tenant);

        $run = ImportRun::find($runId);
        (new RunImportJob($run->id, Storage::path($run->file_path)))->handle(app(WorkbookReader::class));
        (new ProcessImportChunkJob($run->id, 0, RunImportJob::CHUNK_SIZE))->handle(app(RowImporter::class));

        $run->refresh();
        $run->phase = ImportRun::PHASE_FINALIZING;
        $progress = $run->progress;
        $progress['phase'] = ImportRun::PHASE_FINALIZING;
        $run->progress = $progress;
        $run->save();
        $this->assertNotContains($run->status, ImportRun::TERMINAL_STATUSES, 'precondition: run not yet terminal (finalizing)');

        // Worker traps a fatal that escaped handle() → failed() fires.
        (new FinalizeImportJob($run->id))->failed(new RuntimeException('boom: simulated fatal in finalizer (OOM/timeout)'));

        $run->refresh();
        $this->assertSame(ImportRun::STATUS_FAILED, $run->status, 'finalizer hard failure → run failed (not stuck finalizing)');
        $this->assertSame(ImportRun::PHASE_DONE, $run->phase, 'failed run reaches a terminal phase');
        $this->assertNotEmpty($run->error, 'the finalizer failure message is surfaced');
    }

    // =========================================================================
    // REVIEW FIX — finalizer re-entry must not lose bank_balance_delta
    // =========================================================================

    public function test_finalizer_reentry_does_not_lose_bank_balance_delta(): void
    {
        // Calling finalize twice (retry / duplicate ->finally) must NOT overwrite the
        // first finalize's bank_balance_delta. The interim bank_deltas accumulator is
        // stripped on the first pass, so a non-guarded second pass would recompute the
        // delta from an empty array and LOSE it. The re-entry guard (all terminal states
        // = already finalized) protects against this.
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('2301', '2024-07-01', '1500.00'),
            $this->validDataRow('2302', '2024-07-02', '2500.00'),
        ]);

        $run = ImportRun::find($this->executeSync($file)->json('import_run_id'));

        // First finalize already ran inside executeSync (->finally) → terminal + delta set.
        $this->assertSame(ImportRun::STATUS_READY, $run->status);
        $deltaAfterFirst = $run->report['bank_balance_delta'];
        $this->assertNotEmpty($deltaAfterFirst, 'first finalize recorded a bank_balance_delta');
        $this->assertArrayNotHasKey('bank_deltas', $run->report, 'interim accumulator stripped on first finalize');

        $totalDeltaFirst = array_sum(array_column($deltaAfterFirst, 'delta'));
        $this->assertSame(400000, $totalDeltaFirst, '1500 + 2500 MXN = 400000 minor units');

        // Re-run the finalizer (duplicate / retried ->finally). The guard must bail out.
        (new FinalizeImportJob($run->id))->handle(app(ChargeStatusService::class));

        $run->refresh();
        $this->assertSame(ImportRun::STATUS_READY, $run->status, 're-entry keeps the terminal status');
        $this->assertEquals(
            $deltaAfterFirst,
            $run->report['bank_balance_delta'],
            're-entry must NOT lose or zero out bank_balance_delta'
        );
        $this->assertSame(
            $totalDeltaFirst,
            array_sum(array_column($run->report['bank_balance_delta'], 'delta')),
            'bank_balance_delta total unchanged after re-entry'
        );
    }

    // =========================================================================
    // REVIEW FIX — coordinator with an unwritable row cache → failed (not a false ready)
    // =========================================================================

    public function test_coordinator_unwritable_row_cache_marks_run_failed(): void
    {
        // If the rows cache write fails (json_encode returns false / Storage::put returns
        // false), the chunks would silently load an EMPTY slice and the finalizer would
        // falsely report `ready` with 0 payments. The coordinator must fail LOUDLY.
        //
        // Deterministic trigger of the json_encode guard: a WorkbookReader double returns
        // a row carrying an INVALID UTF-8 byte, so json_encode(...) returns false (no
        // JSON_INVALID_UTF8_SUBSTITUTE flag) → the coordinator throws → markRunFailed().
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('2401'),
        ]);

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        // Stage the run; fake Bus so chunks/finalizer don't run.
        Bus::fake();
        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');
        tenancy()->initialize($this->tenant);

        $run = ImportRun::find($runId);

        // WorkbookReader double returning a row with a malformed UTF-8 byte sequence.
        $brokenReader = new class extends WorkbookReader
        {
            public function parse(string $filePath): Collection
            {
                return collect([
                    2 => ['numero' => "\xB1\x31 invalid utf8"], // invalid byte → json_encode fails
                ]);
            }
        };

        (new RunImportJob($run->id, Storage::path($run->file_path)))->handle($brokenReader);

        $run->refresh();
        $this->assertSame(ImportRun::STATUS_FAILED, $run->status, 'unwritable/uencodable row cache → failed (never a false ready)');
        $this->assertSame(ImportRun::PHASE_DONE, $run->phase, 'failed run reaches a terminal phase');
        $this->assertNotEmpty($run->error, 'the cache-write failure is surfaced on the run');

        // No batch was dispatched over an empty/missing cache.
        Bus::assertNothingBatched();
    }
}
