<?php

namespace Modules\DataImport\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Modules\DataImport\Services\WorkbookReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Unit tests for WorkbookReader.
 *
 * These tests create real .xlsx files in a temp directory (no DB required)
 * and assert correct parsing behavior.
 */
class WorkbookReaderTest extends TestCase
{
    private WorkbookReader $reader;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new WorkbookReader;
        $this->tempDir = sys_get_temp_dir().'/workbook_reader_test_'.uniqid();
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up temp files
        foreach (glob($this->tempDir.'/*.xlsx') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Build a temporary .xlsx file with a single "PAGOS" sheet.
     *
     * @param  array<int, array<int, mixed>>  $rows  First element = header row.
     */
    private function buildXlsx(array $rows, string $sheetName = 'PAGOS'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }

        $path = $this->tempDir.'/'.uniqid('test_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_returns_correct_row_count(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500],
            ['Casa', '102', 'Ana', 'López', '2024-01-16', 2000],
            ['Casa', '103', 'Pedro', 'Ramírez', '2024-01-17', 1000],
        ]);

        $rows = $this->reader->parse($path);

        $this->assertCount(3, $rows);
    }

    public function test_row_numbers_are_one_based_and_match_spreadsheet_row(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500],
            ['Casa', '102', 'Ana', 'López', '2024-01-16', 2000],
        ]);

        $rows = $this->reader->parse($path);

        // Keys must be the 1-based row numbers as seen in Excel.
        // Row 1 = headers, so first data row = 2, second = 3.
        $this->assertTrue($rows->has(2), 'Row 2 must exist');
        $this->assertTrue($rows->has(3), 'Row 3 must exist');
        $this->assertFalse($rows->has(1), 'Row 1 (headers) must NOT appear as a data row');
    }

    public function test_spanish_headers_are_mapped_to_internal_keys(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto', 'cuenta_destino'],
            ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500, 'Caja'],
        ]);

        $rows = $this->reader->parse($path);
        $row = $rows->get(2);

        $this->assertIsArray($row);
        $this->assertArrayHasKey('tipo_unidad', $row);
        $this->assertArrayHasKey('numero', $row);
        $this->assertArrayHasKey('nombre', $row);
        $this->assertArrayHasKey('apellido_paterno', $row);
        $this->assertArrayHasKey('fecha_pago', $row);
        $this->assertArrayHasKey('monto', $row);
        $this->assertArrayHasKey('cuenta_destino', $row);
        $this->assertEquals('Casa', $row['tipo_unidad']);
        $this->assertEquals('101', $row['numero']);
        $this->assertEquals('Caja', $row['cuenta_destino']);
    }

    public function test_all_expected_headers_map_correctly(): void
    {
        $allHeaders = array_keys(WorkbookReader::HEADER_MAP);

        $path = $this->buildXlsx([
            $allHeaders,
            array_fill(0, count($allHeaders), 'valor_test'),
        ]);

        $rows = $this->reader->parse($path);
        $row = $rows->get(2);

        $this->assertIsArray($row);
        foreach ($allHeaders as $header) {
            $this->assertArrayHasKey($header, $row, "Internal key '{$header}' must exist in mapped row");
        }
    }

    public function test_throws_when_pagos_sheet_not_found(): void
    {
        // Build xlsx with wrong sheet (no "tipo_unidad" + "monto" headers)
        $path = $this->buildXlsx([
            ['columna_invalida', 'otra_columna'],
            ['valor1', 'valor2'],
        ], 'Hoja1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PAGOS.*encabezados/i');

        $this->reader->parse($path);
    }

    public function test_empty_rows_are_skipped(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500],
            [null, null, null, null, null, null], // empty row — must be skipped
            ['Casa', '103', 'Pedro', 'Ramírez', '2024-01-17', 1000],
        ]);

        $rows = $this->reader->parse($path);

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->has(2));
        $this->assertTrue($rows->has(4)); // row 3 was empty, row 4 is the third data row
        $this->assertFalse($rows->has(3));
    }

    public function test_cell_values_are_trimmed(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['  Casa  ', '  101  ', '  Juan  ', '  García  ', '2024-01-15', 1500],
        ]);

        $rows = $this->reader->parse($path);
        $row = $rows->get(2);

        $this->assertEquals('Casa', $row['tipo_unidad']);
        $this->assertEquals('101', $row['numero']);
    }

    public function test_empty_cells_become_null(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto', 'torre'],
            ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500, ''], // empty torre
        ]);

        $rows = $this->reader->parse($path);
        $row = $rows->get(2);

        $this->assertNull($row['torre'], 'Empty cells must map to null');
    }

    // =========================================================================
    // parsePreview() — bounded read for POST /import/validate (plan 059)
    // =========================================================================

    public function test_preview_returns_all_rows_when_under_the_cap(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500],
            ['Casa', '102', 'Ana', 'López', '2024-01-16', 2000],
            ['Casa', '103', 'Pedro', 'Ramírez', '2024-01-17', 1000],
        ]);

        $preview = $this->reader->parsePreview($path, 10);

        $this->assertFalse($preview['truncated']);
        $this->assertCount(3, $preview['rows']);
    }

    public function test_preview_truncates_and_flags_when_file_exceeds_the_cap(): void
    {
        $rows = [
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
        ];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['Casa', (string) (100 + $i), 'Nombre'.$i, 'Apellido', '2024-01-15', 1000];
        }
        $path = $this->buildXlsx($rows); // 5 data rows, cap = 3

        $preview = $this->reader->parsePreview($path, 3);

        $this->assertTrue($preview['truncated']);
        $this->assertCount(3, $preview['rows']);

        // The FIRST 3 spreadsheet rows (2,3,4) must be the ones kept, not the last 3.
        $this->assertTrue($preview['rows']->has(2));
        $this->assertTrue($preview['rows']->has(3));
        $this->assertTrue($preview['rows']->has(4));
        $this->assertFalse($preview['rows']->has(5));
        $this->assertFalse($preview['rows']->has(6));
        // assertEquals (not assertSame): PhpSpreadsheet's writer infers a NUMERIC
        // cell type from a numeric-looking string (verified identically in parse()
        // and parsePreview() — not specific to the row-cap path), matching the
        // loose-comparison convention every other cell-value assertion in this file
        // already uses for the same reason (see test_spanish_headers_are_mapped_to_internal_keys).
        $this->assertEquals('101', $preview['rows']->get(2)['numero']);
    }

    public function test_preview_does_not_flag_truncation_when_file_has_exactly_the_cap_rows(): void
    {
        // Boundary case: exactly N data rows must NOT be reported as truncated.
        // The "peek one row past the cap" technique must not produce an
        // off-by-one false positive at the exact boundary.
        $rows = [
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
        ];
        for ($i = 1; $i <= 3; $i++) {
            $rows[] = ['Casa', (string) (100 + $i), 'Nombre'.$i, 'Apellido', '2024-01-15', 1000];
        }
        $path = $this->buildXlsx($rows); // exactly 3 data rows, cap = 3

        $preview = $this->reader->parsePreview($path, 3);

        $this->assertFalse($preview['truncated']);
        $this->assertCount(3, $preview['rows']);
    }

    public function test_preview_still_throws_when_pagos_sheet_not_found(): void
    {
        $path = $this->buildXlsx([
            ['columna_invalida', 'otra_columna'],
            ['valor1', 'valor2'],
        ], 'Hoja1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PAGOS.*encabezados/i');

        $this->reader->parsePreview($path, 1000);
    }

    // =========================================================================
    // Date normalization
    //
    // Regression cover for the defect these tests were missing: every other
    // test in this file writes fecha_pago as an ISO STRING, so the reader was
    // never exercised against a cell Excel actually types as a date. A real
    // date cell arrives as a day serial (45672.0) and used to fail the `date`
    // rule, blocking every row of an otherwise valid file.
    // =========================================================================

    /**
     * Build an .xlsx whose fecha_pago cell is a REAL Excel date — a day serial
     * carrying a date number format, exactly what Excel writes when a user
     * types 15/01/2025 into the column.
     */
    private function buildXlsxWithRealDateCell(\DateTimeInterface $date, string $column = 'fecha_pago'): string
    {
        $headers = ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'];
        $values = ['Casa', '101', 'Juan', 'García', '2024-01-15', 1500];

        // Only append the column under test when it is not already part of the
        // base header row. Listing it twice would make normalizeHeaders() keep
        // the LAST index for that key, so the row would read the empty spare
        // column instead of the date cell.
        if (! in_array($column, $headers, true)) {
            $headers[] = $column;
            $values[] = null;
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PAGOS');
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->fromArray([$values], null, 'A2');

        $targetIndex = (int) array_search($column, $headers, true);
        $target = Coordinate::stringFromColumnIndex($targetIndex + 1).'2';

        $sheet->setCellValue($target, ExcelDate::PHPToExcel($date));
        $sheet->getStyle($target)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

        $path = $this->tempDir.'/'.uniqid('test_date_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_real_excel_date_cell_is_normalized_to_iso_string(): void
    {
        $path = $this->buildXlsxWithRealDateCell(new \DateTimeImmutable('2025-01-15'));

        $row = $this->reader->parse($path)->get(2);

        $this->assertSame('2025-01-15', $row['fecha_pago']);
    }

    public function test_normalized_excel_date_passes_the_date_validation_rule(): void
    {
        // The end the defect was really about: the value must survive the exact
        // rule RowImporter::validate() applies to fecha_pago.
        $path = $this->buildXlsxWithRealDateCell(new \DateTimeImmutable('2025-01-15'));

        $row = $this->reader->parse($path)->get(2);

        $validator = Validator::make(
            ['fecha_pago' => $row['fecha_pago']],
            ['fecha_pago' => ['required', 'date']]
        );

        $this->assertFalse($validator->fails(), 'A real Excel date cell must not be rejected as an invalid date.');
    }

    public function test_real_excel_date_is_normalized_in_the_preview_path_too(): void
    {
        $path = $this->buildXlsxWithRealDateCell(new \DateTimeImmutable('2025-01-15'));

        $preview = $this->reader->parsePreview($path, 1000);

        $this->assertSame('2025-01-15', $preview['rows']->get(2)['fecha_pago']);
    }

    public function test_fecha_nacimiento_is_normalized_as_well(): void
    {
        $path = $this->buildXlsxWithRealDateCell(new \DateTimeImmutable('1950-06-20'), 'fecha_nacimiento');

        $row = $this->reader->parse($path)->get(2);

        // Well before the epoch — the serial range must not be clipped to
        // "recent" dates or every older resident's birthdate would break.
        $this->assertSame('1950-06-20', $row['fecha_nacimiento']);
    }

    public function test_day_first_text_date_is_normalized(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '15/01/2025', 1500],
        ]);

        $row = $this->reader->parse($path)->get(2);

        $this->assertSame('2025-01-15', $row['fecha_pago']);
    }

    public function test_ambiguous_text_date_is_read_day_first_not_month_first(): void
    {
        // 01/03/2025 means March 1st in Mexico. PHP's own parser would read it
        // as January 3rd — silently shifting the payment date by two months.
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '01/03/2025', 1500],
        ]);

        $row = $this->reader->parse($path)->get(2);

        $this->assertSame('2025-03-01', $row['fecha_pago']);
    }

    public function test_iso_text_date_is_left_untouched(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', '2025-01-15', 1500],
        ]);

        $row = $this->reader->parse($path)->get(2);

        $this->assertSame('2025-01-15', $row['fecha_pago']);
    }

    public function test_non_date_garbage_in_a_date_column_is_left_for_the_validator(): void
    {
        // Must NOT be coerced into some arbitrary date: the validator stays the
        // single place that decides a value is unacceptable.
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', 'no es fecha', 1500],
        ]);

        $row = $this->reader->parse($path)->get(2);

        $this->assertSame('no es fecha', $row['fecha_pago']);
    }

    public function test_out_of_range_serial_is_left_for_the_validator(): void
    {
        $path = $this->buildXlsx([
            ['tipo_unidad', 'numero', 'nombre', 'apellido_paterno', 'fecha_pago', 'monto'],
            ['Casa', '101', 'Juan', 'García', 0, 1500],
        ]);

        $row = $this->reader->parse($path)->get(2);

        $this->assertEquals(0, $row['fecha_pago']);
    }
}
