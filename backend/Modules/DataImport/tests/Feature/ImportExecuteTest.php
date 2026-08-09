<?php

namespace Modules\DataImport\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\Collections\Models\Payment;
use Modules\DataImport\Models\ImportRun;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for POST /import/execute + GET /import/{id}/status.
 *
 * Uses Queue::fake() for assertions and config('queue.default') = 'sync' for
 * inline execution when needed.
 */
class ImportExecuteTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir().'/import_execute_test_'.uniqid();
        mkdir($this->tempDir, 0775, true);

        // TenantDatabaseSeeder already creates "Casa" and "Activa" — use firstOrCreate to avoid duplicates
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
     * Build a header row matching the PAGOS sheet column order.
     *
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
     * Build a valid data row.
     *
     * @param  array<int, mixed>  $overrides  Column values by index (0-based)
     * @return array<int, mixed>
     */
    private function validDataRow(string $tipoUnidad = 'Casa', string $numero = '101', string $fecha = '2024-03-15', string $monto = '1500.00'): array
    {
        return [
            $tipoUnidad, $numero, null, null, null, 'Activa',
            null, null, null,
            'Juan', 'Pérez', null,
            null, null, null, null,
            null, null, null,
            $fecha, $monto, null, 'Caja Principal',
            null, null, null,
        ];
    }

    /**
     * Build a blocked row (monto missing → error).
     *
     * @return array<int, mixed>
     */
    private function blockedDataRow(): array
    {
        $row = $this->validDataRow();
        $row[20] = null; // monto column (index 20)

        return $row;
    }

    /**
     * Build an xlsx UploadedFile from rows (first element = header).
     *
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
     * Run the execute endpoint and dispatch the job synchronously.
     *
     * Uses sync queue so RunImportJob runs inline. Fakes ONLY the receipt PDF job
     * so the main import job executes for real while avoiding PDF generation side effects.
     *
     * Re-initializes tenancy after the request so that subsequent model queries in the
     * test body remain scoped to the correct tenant (HTTP terminate() ends tenancy).
     */
    private function executeSync(UploadedFile $file): TestResponse
    {
        // Use sync queue so RunImportJob runs inline (not captured by fake)
        config(['queue.default' => 'sync']);
        // Only fake the inner PDF generation job — RunImportJob must run for real
        Queue::fake([GenerateReceiptPdfJob::class]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file]);

        // Re-initialize tenancy so post-request assertions have the correct TenantScope.
        // The HTTP terminate() hook calls tenancy()->end(), leaving tenancy uninitialized.
        tenancy()->initialize($this->tenant);

        return $response;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_execute_returns_import_run_id(): void
    {
        Queue::fake();

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure(['import_run_id']);

        $runId = $response->json('import_run_id');
        $this->assertNotNull($runId);

        // Run must exist in DB
        $this->assertDatabaseHas('import_run', ['id' => $runId]);
    }

    public function test_status_endpoint_returns_run_fields(): void
    {
        Queue::fake();

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file]);

        $runId = $response->json('import_run_id');

        // Poll status
        $statusResponse = $this->tenantRequest($this->token, $this->tenant)
            ->getJson("/api/import/{$runId}/status");

        // The status payload is extended (additive) with `phase` + `error` so the FE
        // phase label and failure banner work — the FE slice depends on this contract.
        $statusResponse->assertStatus(200)
            ->assertJsonStructure(['status', 'phase', 'progress', 'report', 'error']);
    }

    public function test_execute_processes_file_synchronously(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $paymentsBefore = Payment::count();

        $response = $this->executeSync($file);

        $response->assertStatus(200);
        $runId = $response->json('import_run_id');

        // After sync execution, payment must be registered
        $this->assertGreaterThan($paymentsBefore, Payment::count(), '1 new payment must be registered');

        // Status must be ready
        $run = ImportRun::find($runId);
        $this->assertSame(ImportRun::STATUS_READY, $run->status);
    }

    public function test_reexecute_same_file_produces_zero_new_payments(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('Casa', '101', '2024-04-01', '1000.00'),
        ]);

        // First execution
        $this->executeSync($file);
        $paymentCountAfterFirst = Payment::count();

        // Rebuild the same file (must regenerate file content for upload)
        $file2 = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('Casa', '101', '2024-04-01', '1000.00'),
        ], 'test2.xlsx');

        // Second execution of same data
        $this->executeSync($file2);

        $this->assertSame(
            $paymentCountAfterFirst,
            Payment::count(),
            'Re-executing same file must produce zero new payments'
        );
    }

    public function test_blocked_row_is_counted_as_failed_not_executed(): void
    {
        // Under the chunked engine, execution does NOT repeat the /validate dry-run
        // (spec REQ-08): it trusts /validate gated blocked rows and applies only a
        // lightweight defensive guard. A blocked row (monto=null) that reaches
        // execution is therefore counted as `failed` — NOT silently turned into a
        // $0 payment — and the run ends in completed_with_errors.
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('Casa', '201'),    // valid row
            $this->blockedDataRow(),                // blocked (monto=null)
        ]);

        $paymentsBefore = Payment::count();

        $response = $this->executeSync($file);

        $runId = $response->json('import_run_id');
        $run = ImportRun::find($runId);

        // Only 1 valid row → 1 payment; the blocked row never registers a payment.
        $this->assertSame($paymentsBefore + 1, Payment::count(), 'Only 1 payment for the valid row');

        // Report: 1 registered, 1 failed; status distinct from a clean success.
        $report = $run->report;
        $this->assertSame(1, $report['payments_registered']);
        $this->assertSame(1, $report['failed_count']);
        $this->assertSame(1, $report['succeeded_count']);
        $this->assertSame(ImportRun::STATUS_COMPLETED_WITH_ERRORS, $run->status);

        // The failed row is surfaced with its row number + error.
        $errorRows = collect($report['rows'])->where('severity', 'error');
        $this->assertGreaterThanOrEqual(1, $errorRows->count());
    }

    public function test_final_report_contains_correct_counts(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('Casa', '101'),
            $this->validDataRow('Casa', '202'),
        ]);

        $response = $this->executeSync($file);

        $runId = $response->json('import_run_id');
        $run = ImportRun::find($runId);

        $this->assertSame(ImportRun::STATUS_READY, $run->status);

        $report = $run->report;
        $this->assertArrayHasKey('units_created', $report);
        $this->assertArrayHasKey('residents_created', $report);
        $this->assertArrayHasKey('payments_registered', $report);
        $this->assertArrayHasKey('payments_skipped', $report);
        $this->assertArrayHasKey('bank_balance_delta', $report);

        // 2 rows executed → 2 payments registered
        $this->assertSame(2, $report['payments_registered']);
        $this->assertSame(0, $report['payments_skipped']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $this->postJson('/api/import/execute', ['file' => $file])
            ->assertStatus(401);
    }

    public function test_execute_is_forbidden_without_create_data_import_ability(): void
    {
        // Token WITHOUT create.data_import (or wildcard) must be rejected with 403.
        $user = $this->makeUser($this->tenant);
        $token = $user->createToken('limited', ['read.charge'])->plainTextToken;

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $this->tenantRequest($token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->assertStatus(403);
    }

    public function test_status_is_forbidden_without_read_data_import_ability(): void
    {
        // First create a run with a wildcard token.
        Queue::fake();

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');

        // A token WITHOUT read.data_import (or wildcard) cannot poll the status.
        $user = $this->makeUser($this->tenant);
        $token = $user->createToken('limited', ['read.charge'])->plainTextToken;

        $this->tenantRequest($token, $this->tenant)
            ->getJson("/api/import/{$runId}/status")
            ->assertStatus(403);
    }

    public function test_non_xlsx_file_is_rejected(): void
    {
        $csvFile = UploadedFile::fake()->create('pagos.csv', 100, 'text/csv');

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $csvFile])
            ->assertStatus(422);
    }
}
