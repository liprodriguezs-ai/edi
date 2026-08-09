<?php

namespace Modules\DataImport\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Modules\Collections\Models\Payment;
use Modules\DataImport\Services\WorkbookReader;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for POST /import/validate endpoint.
 *
 * Verifies the synchronous dry-run:
 * - HTTP 422 when blocked > 0
 * - HTTP 200 when blocked === 0 (even with warnings)
 * - ZERO DB writes at any point
 * - Correct issue aggregation
 */
class ImportValidateTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir().'/import_validate_test_'.uniqid();
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

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build an xlsx file with the given data rows (first element = header row).
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildXlsx(array $rows, string $filename = 'test.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

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
     * Build a minimal valid header row matching WorkbookReader::HEADER_MAP.
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
     * @return array<int, mixed>
     */
    private function validDataRow(string $tipoUnidad = 'Casa', string $numero = '101'): array
    {
        return [
            $tipoUnidad, $numero, null, null, null, null,
            null, null, null,
            'Juan', 'Pérez', null,
            null, null, null, null,
            null, null, null,
            '2024-03-15', '1500.00', null, null,
            null, null, null,
        ];
    }

    /**
     * Build a data row with a mandatory field missing (produces severity=error).
     *
     * @return array<int, mixed>
     */
    private function blockedDataRow(): array
    {
        $row = $this->validDataRow();
        $row[20] = null; // monto column (index 20)

        return $row;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_returns_422_when_blocked_rows_exist(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
            $this->blockedDataRow(), // row 3 — blocked (monto missing)
        ]);

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('blocked', fn ($v) => $v > 0);
    }

    public function test_returns_200_when_no_blocked_rows(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
            $this->validDataRow('Departamento', '202'),
        ]);

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('blocked', 0);
    }

    public function test_response_contains_required_fields(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'units_to_create',
                'residents_to_create',
                'payments_to_register',
                'blocked',
                'rows',
            ]);
    }

    public function test_three_blocked_rows_produces_blocked_count_3(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),     // row 2 — valid
            $this->blockedDataRow(),   // row 3 — blocked
            $this->blockedDataRow(),   // row 4 — blocked
            $this->blockedDataRow(),   // row 5 — blocked
        ]);

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('blocked', 3)
            ->assertJsonPath('total', 4);
    }

    public function test_validate_is_dry_run_no_units_created(): void
    {
        $unitCountBefore = Unit::count();
        $paymentCountBefore = Payment::count();
        $unitUserCountBefore = UnitUser::count();

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
            $this->validDataRow('Casa', '202'),
        ]);

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file]);

        // Re-initialize tenancy after tenantRequest() ended it, so BelongsToTenant
        // scopes the count queries to the current test's tenant only.
        tenancy()->initialize($this->tenant);

        $this->assertEquals($unitCountBefore, Unit::count(), 'Unit count should not change on validate');
        $this->assertEquals($paymentCountBefore, Payment::count(), 'Payment count should not change on validate');
        $this->assertEquals($unitUserCountBefore, UnitUser::count(), 'UnitUser count should not change on validate');
    }

    public function test_file_with_warnings_only_returns_200(): void
    {
        // A row with an unregistered tipo_unidad produces warning (not error) → HTTP 200
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow('PH'), // 'PH' not in tenant catalog → warning only
        ]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('blocked', 0);

        // Should have warning row
        $rows = $response->json('rows');
        $this->assertIsArray($rows);
        // There might be a warning for unregistered tipo_unidad
    }

    public function test_422_response_includes_errors_keyed_by_row_and_col(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->blockedDataRow(), // row 2 — monto missing
        ]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file]);

        $response->assertStatus(422);
        $errors = $response->json('errors');

        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);

        // Each key must follow rows.<n>.<col> format
        foreach (array_keys($errors) as $key) {
            $this->assertMatchesRegularExpression('/^rows\.\d+\.[a-z_]+$/', $key);
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $this->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(401);
    }

    public function test_validate_is_forbidden_without_create_data_import_ability(): void
    {
        // Token WITHOUT create.data_import (or wildcard) must be rejected with 403.
        $user = $this->makeUser($this->tenant);
        $token = $user->createToken('limited', ['read.charge'])->plainTextToken;

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
        ]);

        $this->tenantRequest($token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(403);
    }

    public function test_template_is_forbidden_without_read_data_import_ability(): void
    {
        // Token WITHOUT read.data_import (or wildcard) must be rejected with 403.
        $user = $this->makeUser($this->tenant);
        $token = $user->createToken('limited', ['read.charge'])->plainTextToken;

        $this->tenantRequest($token, $this->tenant)
            ->getJson('/api/import/template')
            ->assertStatus(403);
    }

    public function test_non_xlsx_file_is_rejected(): void
    {
        $csvFile = UploadedFile::fake()->create('pagos.csv', 100, 'text/csv');

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $csvFile])
            ->assertStatus(422);
    }

    public function test_returns_422_when_pagos_sheet_is_missing(): void
    {
        // A workbook whose first row lacks the mandatory headers (tipo_unidad + monto)
        // is a malformed user file. It must come back as 422 — NOT 500 — so the
        // frontend can surface the reader's guidance instead of a generic error.
        $file = $this->buildXlsx([
            ['columna_uno', 'columna_dos', 'otra_cosa'],
            ['foo', 'bar', 'baz'],
        ]);

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($v) => is_string($v) && str_contains($v, 'PAGOS'));
    }

    // =========================================================================
    // Row cap — bounded validate dry-run (plan 059)
    // =========================================================================

    /**
     * The row cap is overridden to a small number so the fixture stays fast
     * while still exercising the real config -> controller -> WorkbookReader
     * wiring end-to-end (the unit-level boundary/off-by-one cases already
     * live in WorkbookReaderTest).
     */
    public function test_validate_reports_truncation_when_file_exceeds_the_row_cap(): void
    {
        config(['dataimport.validate_row_cap' => 3]);

        $rows = [$this->headerRow()];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->validDataRow('Casa', (string) (100 + $i));
        }

        $file = $this->buildXlsx($rows, 'large.xlsx');

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('row_cap', 3)
            ->assertJsonPath('total', 3, 'Only the first row_cap rows are validated/reported — not the whole file')
            ->assertJsonPath('preview_message', fn ($v) => is_string($v) && str_contains($v, '3'));
    }

    public function test_validate_response_has_no_truncation_flag_when_under_the_row_cap(): void
    {
        config(['dataimport.validate_row_cap' => 1000]);

        $file = $this->buildXlsx([
            $this->headerRow(),
            $this->validDataRow(),
            $this->validDataRow('Departamento', '202'),
        ]);

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);

        $this->assertArrayNotHasKey(
            'truncated',
            $response->json(),
            'The truncation flag must only appear when the file actually exceeds the row cap'
        );
    }

    public function test_execute_is_not_bounded_by_the_validate_row_cap(): void
    {
        // Sanity guard for the plan's hard constraint: EXECUTE must stay
        // full-fidelity even when the validate row cap is tiny. RunImportJob
        // calls WorkbookReader::parse() (unbounded), never parsePreview().
        config(['dataimport.validate_row_cap' => 2]);

        $rows = [$this->headerRow()];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->validDataRow('Casa', (string) (300 + $i));
        }

        $path = sys_get_temp_dir().'/'.uniqid('execute_unbounded_').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }
        (new Xlsx($spreadsheet))->save($path);

        $reader = app(WorkbookReader::class);
        $allRows = $reader->parse($path);

        unlink($path);

        $this->assertCount(5, $allRows, 'parse() (used by EXECUTE) must remain unbounded regardless of the validate row cap');
    }
}
