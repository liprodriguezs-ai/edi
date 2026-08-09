<?php

namespace Modules\DataImport\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Telescope\Telescope;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\DataImport\Jobs\Concerns\SilencesInstrumentation;
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
 * InstrumentationTest — SCENARIO-08: the kill-switch is JOB-SCOPED.
 *
 * Root cause of the original OOM-at-44-rows: per-query instrumentation accumulators
 * (DB query log + Telescope + overtrue/laravel-query-logger) retain one entry per
 * query across the loop. The fix (SilencesInstrumentation) disables all three at the
 * start of every import job and RESTORES them in a finally block so the long-lived
 * worker keeps recording for later, unrelated jobs.
 *
 * These tests assert:
 *  - DURING silence: the DB query log is disabled (empty), the query-logger config is
 *    forced into its off state.
 *  - AFTER restore: the query-logger config is restored EXACTLY, and Telescope recording
 *    is restored to its pre-silence value (job-scoped, not a global blind).
 *  - A real import run leaves the query-logger config untouched once finished.
 */
class InstrumentationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected string $token;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        $this->seedTenant($this->tenant);
        tenancy()->initialize($this->tenant);

        $user = $this->makeUser($this->tenant);
        $this->token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tempDir = sys_get_temp_dir().'/import_instrumentation_'.uniqid();
        mkdir($this->tempDir, 0775, true);

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

    /**
     * A tiny host object that uses the real trait so we can drive its protected
     * silence/restore lifecycle and observe the in-flight + restored state.
     */
    private function makeSilencer(): object
    {
        return new class
        {
            use SilencesInstrumentation;

            public function silenceNow(): void
            {
                $this->silenceInstrumentation();
            }

            public function restoreNow(): void
            {
                $this->restoreInstrumentation();
            }
        };
    }

    // =========================================================================
    // During silence: query log + logger config are off
    // =========================================================================

    public function test_silence_disables_query_log_and_forces_logger_off(): void
    {
        // Pre-state: enable the query log + a "recording" logger config so we can prove
        // the silencer flips them off.
        DB::connection()->enableQueryLog();
        Config::set('logging.query.enabled', true);
        Config::set('logging.query.except', []);
        Config::set('logging.query.slower_than', 0);

        $silencer = $this->makeSilencer();
        $silencer->silenceNow();

        // The query log is disabled → executing a query records nothing.
        DB::table('import_run')->count();
        $this->assertSame([], DB::connection()->getQueryLog(), 'query log must be empty/disabled during silence');

        // The overtrue/laravel-query-logger off-switch is engaged.
        $this->assertFalse(config('logging.query.enabled'), 'logger enabled flag forced off');
        $this->assertSame(['*'], config('logging.query.except'), "except must match every query ('*')");
        $this->assertSame(PHP_INT_MAX, config('logging.query.slower_than'), 'slower_than must never trigger');

        $silencer->restoreNow();
        DB::connection()->disableQueryLog();
    }

    // =========================================================================
    // After restore: logger config restored EXACTLY (job-scoped)
    // =========================================================================

    public function test_restore_returns_logger_config_to_original_values(): void
    {
        // Arrange a distinctive original config.
        Config::set('logging.query.enabled', true);
        Config::set('logging.query.except', ['some/specific/path']);
        Config::set('logging.query.slower_than', 250);

        $silencer = $this->makeSilencer();
        $silencer->silenceNow();

        // (during silence the values are overridden — verified in the test above)
        $silencer->restoreNow();

        // Restored EXACTLY — the kill-switch did not permanently blind the logger.
        $this->assertTrue(config('logging.query.enabled'), 'enabled restored');
        $this->assertSame(['some/specific/path'], config('logging.query.except'), 'except restored');
        $this->assertSame(250, config('logging.query.slower_than'), 'slower_than restored');
    }

    public function test_restore_returns_telescope_recording_to_pre_silence_state(): void
    {
        // Telescope is installed (class_exists true) but disabled in testing, so it is
        // NOT recording. The contract: a non-recording Telescope stays non-recording
        // after silence+restore (the switch never turns it ON spuriously).
        $wasRecording = Telescope::isRecording();

        $silencer = $this->makeSilencer();
        $silencer->silenceNow();
        $silencer->restoreNow();

        $this->assertSame(
            $wasRecording,
            Telescope::isRecording(),
            'Telescope recording state must be restored to its pre-silence value'
        );
    }

    public function test_restore_is_a_safe_noop_when_silence_never_called(): void
    {
        // restoreInstrumentation() must be safe to call even if silence was skipped.
        $before = config('logging.query.except');

        $silencer = $this->makeSilencer();
        $silencer->restoreNow();

        $this->assertSame($before, config('logging.query.except'), 'restore without silence is a no-op');
    }

    // =========================================================================
    // End-to-end: a real import leaves the logger config untouched afterward
    // =========================================================================

    public function test_real_import_run_leaves_logger_config_restored(): void
    {
        // Capture the ambient config before the run.
        $originalEnabled = config('logging.query.enabled');
        $originalExcept = config('logging.query.except');
        $originalSlowerThan = config('logging.query.slower_than');

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('801'),
            $this->validDataRow('802'),
        ]);

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');

        tenancy()->initialize($this->tenant);

        // The run completed (so the silence happened) ...
        $this->assertSame(ImportRun::STATUS_READY, ImportRun::find($runId)->status);

        // ... and the kill-switch did NOT leak: config is exactly as before the run.
        $this->assertSame($originalEnabled, config('logging.query.enabled'), 'enabled restored after run');
        $this->assertSame($originalExcept, config('logging.query.except'), 'except restored after run');
        $this->assertSame($originalSlowerThan, config('logging.query.slower_than'), 'slower_than restored after run');
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
    private function validDataRow(string $numero): array
    {
        return [
            'Casa', $numero, null, null, null, 'Activa',
            null, null, null,
            'Juan', 'Pérez', null,
            null, null, null, null,
            null, null, null,
            '2024-03-15', '1500.00', null, 'Caja Principal',
            null, null, null,
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildXlsx(array $rows, string $filename = 'instr.xlsx'): UploadedFile
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
}
