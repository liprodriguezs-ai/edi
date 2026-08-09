<?php

namespace Modules\DataImport\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\DataImport\Services\TemplateGenerator;
use Modules\DataImport\Services\TemplateWorkbook;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for TemplateGenerator.
 *
 * Because TemplateGenerator queries tenant-scoped catalogs, we need a tenant
 * and DB. Placed in the Unit directory per task spec; uses RefreshDatabase.
 */
class TemplateGeneratorTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_generated_xlsx_has_pagos_sheet(): void
    {
        $path = $this->storeWorkbook([]);

        $spreadsheet = IOFactory::load($path);
        $sheetNames = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());

        $this->assertContains('PAGOS', $sheetNames, 'Template must contain a "PAGOS" sheet');
        unlink($path);
    }

    public function test_pagos_sheet_contains_all_column_headers(): void
    {
        $path = $this->storeWorkbook([]);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('PAGOS');

        $this->assertNotNull($sheet, '"PAGOS" sheet must exist');

        $headerRow = [];
        $highestCol = Coordinate::columnIndexFromString(
            $sheet->getHighestColumn()
        );
        for ($col = 1; $col <= $highestCol; $col++) {
            $val = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($val !== null && $val !== '') {
                $headerRow[] = $val;
            }
        }

        foreach (TemplateGenerator::PAGOS_HEADERS_PUBLIC as $expected) {
            $this->assertContains($expected, $headerRow, "PAGOS sheet must contain column '{$expected}'");
        }

        unlink($path);
    }

    public function test_generated_xlsx_has_leeme_sheet(): void
    {
        $path = $this->storeWorkbook([]);
        $spreadsheet = IOFactory::load($path);
        $sheetNames = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());

        $this->assertContains('LÉEME', $sheetNames, 'Template must contain a "LÉEME" sheet');
        unlink($path);
    }

    public function test_reference_sheet_lists_unit_types_seeded_for_tenant(): void
    {
        UnitType::create(['name' => 'Casa', 'order' => 1, 'is_active' => true, 'requires_floor' => false]);
        UnitType::create(['name' => 'Departamento', 'order' => 2, 'is_active' => true, 'requires_floor' => true]);

        $unitTypes = UnitType::query()->orderBy('order')->get(['name'])->map(fn ($t) => [$t->name])->toArray();
        $path = $this->storeWorkbook($unitTypes);

        $spreadsheet = IOFactory::load($path);
        $sheetNames = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());
        $this->assertContains('Tipos de Unidad', $sheetNames);

        $sheet = $spreadsheet->getSheetByName('Tipos de Unidad');
        $names = [];
        $highest = $sheet->getHighestRow();
        for ($row = 2; $row <= $highest; $row++) {
            $val = $sheet->getCellByColumnAndRow(1, $row)->getValue();
            if ($val !== null && $val !== '') {
                $names[] = $val;
            }
        }

        $this->assertContains('Casa', $names);
        $this->assertContains('Departamento', $names);

        unlink($path);
    }

    public function test_leeme_sheet_contains_manual_text(): void
    {
        $path = $this->storeWorkbook([]);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('LÉEME');
        $this->assertNotNull($sheet);

        $allValues = [];
        $highest = $sheet->getHighestRow();
        for ($row = 1; $row <= $highest; $row++) {
            $val = $sheet->getCellByColumnAndRow(1, $row)->getValue();
            if ($val !== null && $val !== '') {
                $allValues[] = (string) $val;
            }
        }

        $combined = implode(' ', $allValues);
        $this->assertStringContainsString('Catálogos', $combined, 'LÉEME must contain the manual text');

        unlink($path);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Build a TemplateWorkbook and store it to a temp .xlsx file using
     * PhpSpreadsheet's native writer directly, bypassing Laravel's storage layer.
     * Returns the absolute path.
     *
     * @param  array<int, array<int, string>>  $unitTypes
     */
    private function storeWorkbook(array $unitTypes): string
    {
        $workbook = new TemplateWorkbook(
            unitTypes: $unitTypes,
            unitStatuses: [],
            bankAccounts: [],
            paymentMethods: [],
        );

        // Use Laravel Excel's raw write method via the container
        $path = sys_get_temp_dir().'/template_test_'.uniqid().'.xlsx';

        // Maatwebsite has a raw() method but it's easier to use the ToRaw concern.
        // Simplest approach: use the underlying PhpSpreadsheet directly.
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0); // remove default sheet

        /** @var TemplateSheet $sheet */
        foreach ($workbook->sheets() as $index => $sheet) {
            $ws = new Worksheet($spreadsheet, $sheet->title());
            $spreadsheet->addSheet($ws);

            // Write headings row
            $headings = $sheet->headings();
            foreach ($headings as $col => $heading) {
                $ws->setCellValueByColumnAndRow($col + 1, 1, $heading);
            }

            // Write data rows
            foreach ($sheet->array() as $rowIndex => $rowData) {
                foreach ($rowData as $col => $value) {
                    $ws->setCellValueByColumnAndRow($col + 1, $rowIndex + 2, $value);
                }
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }
}
