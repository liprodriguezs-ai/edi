<?php

namespace Modules\DataImport\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\Collections\Models\Payment;
use Modules\DataImport\Jobs\ProcessImportChunkJob;
use Modules\DataImport\Jobs\RunImportJob;
use Modules\DataImport\Models\ImportRun;
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
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\TenantScope;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * CrossTenantIsolationTest — SCENARIO-05 (HIGHEST RISK).
 *
 * The chunked import engine passes ONLY the import_run_id (a string) into each
 * chunk/finalizer job. Tenancy must be re-established per job from the queue
 * payload via QueueTenancyBootstrapper. If that breaks, an import dispatched for
 * tenant A could read tenant B's catalogs or write rows under tenant B.
 *
 * This file is rigorous on purpose:
 *  1. Behavioral end-to-end: an import for tenant A creates ZERO rows under tenant B.
 *  2. Payload mechanism: a job dispatched while tenant A is initialized serializes
 *     tenant_id = A into its queue payload (the contract QueueTenancyBootstrapper
 *     re-initializes from), and NEVER tenant B's id.
 *  3. The run's own tenant_id is A; B sees no import_run at all.
 */
class CrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected string $tokenA;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);

        $this->tenantA = $this->makeTenant('Cluster A');
        $this->tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($this->tenantA);
        $this->seedTenant($this->tenantB);

        // Provision catalogs + a bank account for BOTH tenants so each could, in
        // principle, accept an import — making any cross-tenant leak observable.
        $this->provisionTenant($this->tenantA);
        $this->provisionTenant($this->tenantB);

        $adminA = $this->makeUser($this->tenantA);
        $this->tokenA = $adminA->createToken('admin', ['*'])->plainTextToken;

        $this->tempDir = sys_get_temp_dir().'/cross_tenant_import_'.uniqid();
        mkdir($this->tempDir, 0775, true);
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

    private function provisionTenant(Tenant $tenant): void
    {
        $tenant->run(function (): void {
            UnitType::firstOrCreate(
                ['name' => 'Casa', 'tenant_id' => tenant('id')],
                ['order' => 1, 'is_active' => true, 'requires_floor' => false]
            );
            UnitStatus::firstOrCreate(
                ['name' => 'Activa', 'tenant_id' => tenant('id')],
                ['order' => 1, 'is_active' => true, 'is_billable' => true]
            );

            $purpose = BankAccountPurpose::where('tenant_id', tenant('id'))->first()
                ?? BankAccountPurpose::create(['name' => 'General', 'order' => 1, 'is_active' => true]);

            BankAccount::firstOrCreate(
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
        });
    }

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
    private function validDataRow(string $numero, string $monto): array
    {
        return [
            'Casa', $numero, null, null, null, 'Activa',
            null, null, null,
            'Juan', 'Pérez', null,
            null, null, null, null,
            null, null, null,
            '2024-03-15', $monto, null, 'Caja Principal',
            null, null, null,
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildXlsx(array $rows, string $filename = 'cross.xlsx'): UploadedFile
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
     * Count payments for a tenant WITHOUT the global TenantScope (so a leak into the
     * wrong tenant is detectable, not hidden by the scope itself).
     */
    private function paymentCountFor(Tenant $tenant): int
    {
        return Payment::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->count();
    }

    private function unitCountFor(Tenant $tenant): int
    {
        return Unit::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->count();
    }

    // =========================================================================
    // 1. Behavioral end-to-end isolation
    // =========================================================================

    public function test_import_for_tenant_a_creates_zero_rows_under_tenant_b(): void
    {
        $bPaymentsBefore = $this->paymentCountFor($this->tenantB);
        $bUnitsBefore = $this->unitCountFor($this->tenantB);

        // 3 distinct valid rows imported as tenant A (via X-Tenant-Id header).
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('501', '1000.00'),
            $this->validDataRow('502', '2000.00'),
            $this->validDataRow('503', '3000.00'),
        ]);

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $response = $this->tenantRequest($this->tokenA, $this->tenantA)
            ->postJson('/api/import/execute', ['file' => $file]);

        $response->assertStatus(200);

        // ── Tenant A got everything ──────────────────────────────────────────
        tenancy()->initialize($this->tenantA);
        $run = ImportRun::find($response->json('import_run_id'));
        $this->assertSame(ImportRun::STATUS_READY, $run->status);
        $this->assertSame(3, $run->report['payments_registered']);
        $this->assertSame($this->tenantA->id, $run->tenant_id, 'the run itself belongs to tenant A');
        $this->assertSame(3, $this->paymentCountFor($this->tenantA), 'tenant A received its 3 payments');
        tenancy()->end();

        // ── Tenant B got NOTHING ─────────────────────────────────────────────
        $this->assertSame(
            $bPaymentsBefore,
            $this->paymentCountFor($this->tenantB),
            'ZERO payments leaked into tenant B'
        );
        $this->assertSame(
            $bUnitsBefore,
            $this->unitCountFor($this->tenantB),
            'ZERO units leaked into tenant B'
        );

        // No import_run exists for tenant B.
        $this->assertSame(
            0,
            ImportRun::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantB->id)->count(),
            'tenant B has no import_run'
        );
    }

    // =========================================================================
    // 2. Payload mechanism: QueueTenancyBootstrapper carries the right tenant
    // =========================================================================

    public function test_chunk_jobs_are_dispatched_under_tenant_a_context(): void
    {
        // The controller stores the file + creates the run, THEN faking Bus lets us run
        // the coordinator's handle() directly and capture the inner Bus::batch() it
        // dispatches — verifying the chunk jobs are produced while tenant A is active.
        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('601', '1000.00'),
            $this->validDataRow('602', '2000.00'),
        ]);

        // Bus::fake AFTER the dispatch would miss it; fake first so the controller's
        // RunImportJob dispatch is captured, then run that coordinator manually.
        Bus::fake();

        $runId = $this->tenantRequest($this->tokenA, $this->tenantA)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');

        tenancy()->initialize($this->tenantA);

        $run = ImportRun::find($runId);
        $coordinator = new RunImportJob($run->id, Storage::path($run->file_path));
        $coordinator->handle(app(WorkbookReader::class));

        // The batch was dispatched and contains only ProcessImportChunkJob instances.
        Bus::assertBatched(function ($batch): bool {
            return $batch->jobs->isNotEmpty()
                && $batch->jobs->every(fn ($job): bool => $job instanceof ProcessImportChunkJob);
        });
    }

    public function test_queue_payload_carries_tenant_a_id_not_tenant_b(): void
    {
        // Direct assertion on the QueueTenancyBootstrapper contract: while tenant A is
        // initialized, the payload created for ANY tenant-aware job on a non-central
        // connection carries tenant_id = A. The chunk job is passed only an id, so this
        // payload is the SOLE channel that re-establishes tenancy for the chunk.
        tenancy()->initialize($this->tenantA);

        $bootstrapper = app(QueueTenancyBootstrapper::class);

        $payload = $bootstrapper->getPayload('database');

        $this->assertSame(
            $this->tenantA->id,
            $payload['tenant_id'] ?? null,
            'queue payload must carry tenant A id'
        );
        $this->assertNotSame(
            $this->tenantB->id,
            $payload['tenant_id'] ?? null,
            'queue payload must NEVER carry tenant B id while A is active'
        );

        // Sanity: when no tenant is active, the payload is empty (central).
        tenancy()->end();
        $this->assertSame([], $bootstrapper->getPayload('database'), 'central payload is empty when no tenant active');

        tenancy()->initialize($this->tenantA);
    }

    public function test_chunk_job_finds_run_only_within_its_own_tenant(): void
    {
        // A chunk job carrying tenant B's payload must NOT see tenant A's import_run:
        // the tenant-scoped find returns null (safe 404), never a cross-tenant row.
        tenancy()->initialize($this->tenantA);
        $runA = ImportRun::create([
            'status' => ImportRun::STATUS_PROCESSING,
            'phase' => ImportRun::PHASE_PROCESSING,
            'file_path' => 'imports/'.$this->tenantA->id.'/x.xlsx',
            'progress' => ['processed' => 0, 'total' => 0, 'phase' => ImportRun::PHASE_PROCESSING],
            'report' => ImportRun::emptyReport(),
        ]);
        tenancy()->end();

        // Re-scope as tenant B and assert A's run is invisible (TenantScope enforced).
        tenancy()->initialize($this->tenantB);
        $this->assertNull(ImportRun::find($runA->id), 'tenant B must not resolve tenant A run id');
        $this->assertSame(
            $runA->id,
            ImportRun::withoutGlobalScope(TenantScope::class)->find($runA->id)?->id,
            'the run still exists globally — it is the SCOPE, not deletion, that isolates it'
        );
        tenancy()->end();
    }
}
