<?php

namespace Modules\DataImport\Services;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * WorkbookReader — reads the "PAGOS" sheet from an uploaded .xlsx file.
 *
 * Returns a Collection keyed by 1-based spreadsheet row number (row 2 in the
 * spreadsheet = key 2, matching the row number the user sees in Excel).
 * Each element is an associative array with internal (Spanish key) field names.
 */
class WorkbookReader
{
    /**
     * The expected sheet name.
     */
    private const SHEET_NAME = 'PAGOS';

    /**
     * Columns that carry a date and therefore need normalizeDateCell() before
     * they reach validation. Every other column keeps its raw cell value.
     *
     * @var array<int, string>
     */
    private const DATE_COLUMNS = [
        'fecha_pago',
        'fecha_nacimiento',
    ];

    /**
     * Highest day serial Excel maps to a real date (9999-12-31). A numeric cell
     * outside [1, this] is not a date at all, so it is left untouched for the
     * validator to reject instead of being silently turned into a bogus date.
     */
    private const MAX_EXCEL_DATE_SERIAL = 2958465;

    /**
     * Map from Spanish column headers (as found in the Excel) to internal keys.
     *
     * The keys match the plan §3 column names exactly.
     *
     * @var array<string, string>
     */
    public const HEADER_MAP = [
        'tipo_unidad' => 'tipo_unidad',
        'numero' => 'numero',
        'torre' => 'torre',
        'piso' => 'piso',
        'seccion' => 'seccion',
        'estado_unidad' => 'estado_unidad',
        'indiviso' => 'indiviso',
        'm2_construido' => 'm2_construido',
        'm2_terreno' => 'm2_terreno',
        'nombre' => 'nombre',
        'apellido_paterno' => 'apellido_paterno',
        'apellido_materno' => 'apellido_materno',
        'email' => 'email',
        'telefono' => 'telefono',
        'relacion' => 'relacion',
        'titular_de_pago' => 'titular_de_pago',
        'rfc' => 'rfc',
        'curp' => 'curp',
        'fecha_nacimiento' => 'fecha_nacimiento',
        'fecha_pago' => 'fecha_pago',
        'monto' => 'monto',
        'metodo_pago' => 'metodo_pago',
        'cuenta_destino' => 'cuenta_destino',
        'referencia' => 'referencia',
        'folio' => 'folio',
        'notas' => 'notas',
    ];

    /**
     * Parse the uploaded file and return normalized rows.
     *
     * @param  string  $filePath  Absolute path to the .xlsx file.
     * @return Collection<int, array<string, mixed>> Rows keyed by 1-based spreadsheet row number.
     *
     * @throws \InvalidArgumentException When the "PAGOS" sheet is not found or headers are missing.
     */
    public function parse(string $filePath): Collection
    {
        $allSheets = Excel::toArray(new \stdClass, $filePath, null, \Maatwebsite\Excel\Excel::XLSX);

        $sheetData = $this->findPagosSheet($allSheets);

        if (empty($sheetData)) {
            return collect();
        }

        // First row = header row
        $headerRow = array_shift($sheetData);
        $headers = $this->normalizeHeaders((array) $headerRow);

        $rows = collect();

        // After the shift, $sheetData[0] is original row 2.
        foreach ($sheetData as $index => $rawRow) {
            $rowNumber = $index + 2; // 1-based; row 2 = first data row

            if ($this->isEmptyRow((array) $rawRow)) {
                continue;
            }

            $rows->put($rowNumber, $this->mapRow($headers, (array) $rawRow));
        }

        return $rows;
    }

    /**
     * Parse the uploaded file for the SYNCHRONOUS validate dry-run, bounding
     * the number of DATA rows materialized so a crafted/huge workbook cannot
     * exhaust worker memory in-request (plan 059).
     *
     * The cap is applied via a PhpSpreadsheet IReadFilter (RowCapImport
     * implementing Maatwebsite\Excel\Concerns\WithLimit), so rows beyond the
     * cap are never turned into PHP cell objects — unlike a post-hoc
     * array_slice(), which would only trim the array AFTER the whole file
     * was already materialized in memory.
     *
     * EXECUTE (RunImportJob) calls parse() instead, which is UNCHANGED and
     * stays full-fidelity — this method exists ONLY for the preview endpoint.
     *
     * @param  string  $filePath  Absolute path to the .xlsx file.
     * @param  int  $maxDataRows  Maximum number of data rows to materialize (post-header).
     * @return array{rows: Collection<int, array<string, mixed>>, truncated: bool}
     *
     * @throws \InvalidArgumentException When the "PAGOS" sheet is not found or headers are missing.
     */
    public function parsePreview(string $filePath, int $maxDataRows): array
    {
        // RowCapImport::limit() counts TOTAL spreadsheet rows starting at row 1
        // (the header row — see its own docblock and Maatwebsite\Excel's
        // LimitFilter, which reads exactly `limit` rows from `startRow`).
        // So +1 row of "peek" headroom is the HEADER, not an extra data row —
        // a bare "$maxDataRows + 1" reads only $maxDataRows DATA rows and the
        // peek row is never materialized, making `count($sheetData) >
        // $maxDataRows` always false. We need header (+1) AND one real peek
        // DATA row past the cap (+1) = "$maxDataRows + 2" total rows: if that
        // peek row comes back populated, the file has strictly more than
        // $maxDataRows data rows, reported without a second, unbounded read.
        $import = new RowCapImport($maxDataRows + 2);
        $allSheets = Excel::toArray($import, $filePath, null, \Maatwebsite\Excel\Excel::XLSX);

        $sheetData = $this->findPagosSheet($allSheets);

        if (empty($sheetData)) {
            return ['rows' => collect(), 'truncated' => false];
        }

        $headerRow = array_shift($sheetData);
        $headers = $this->normalizeHeaders((array) $headerRow);

        $truncated = count($sheetData) > $maxDataRows;
        if ($truncated) {
            $sheetData = array_slice($sheetData, 0, $maxDataRows, true);
        }

        $rows = collect();

        foreach ($sheetData as $index => $rawRow) {
            $rowNumber = $index + 2; // 1-based; row 2 = first data row

            if ($this->isEmptyRow((array) $rawRow)) {
                continue;
            }

            $rows->put($rowNumber, $this->mapRow($headers, (array) $rawRow));
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Find the PAGOS sheet by scanning for mandatory headers.
     *
     * @param  array<int, array<int, array<int, mixed>>>  $allSheets
     * @return array<int, array<int, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    private function findPagosSheet(array $allSheets): array
    {
        foreach ($allSheets as $sheetData) {
            if (empty($sheetData)) {
                continue;
            }

            $firstRow = (array) ($sheetData[0] ?? []);
            $normalized = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $firstRow);

            // The PAGOS sheet must have both mandatory columns
            if (in_array('tipo_unidad', $normalized, true) && in_array('monto', $normalized, true)) {
                return $sheetData;
            }
        }

        throw new \InvalidArgumentException(
            'El archivo no contiene la hoja "'.self::SHEET_NAME.'" con los encabezados esperados '
            .'(tipo_unidad, monto). Descarga la plantilla actualizada e intenta de nuevo.'
        );
    }

    /**
     * Build a map of [columnIndex => internalKey] from the header row.
     *
     * @param  array<int, mixed>  $headerRow
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        $index = [];
        foreach ($headerRow as $colIndex => $value) {
            $normalized = mb_strtolower(trim((string) $value));
            if (isset(self::HEADER_MAP[$normalized])) {
                $index[$colIndex] = self::HEADER_MAP[$normalized];
            }
        }

        return $index;
    }

    /**
     * Map a raw row to [internalKey => value] using the column-index header map.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $rawRow
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $rawRow): array
    {
        $result = [];
        foreach ($headers as $colIndex => $key) {
            $cellValue = $this->normalizeCell($rawRow[$colIndex] ?? null);

            $result[$key] = in_array($key, self::DATE_COLUMNS, true)
                ? $this->normalizeDateCell($cellValue)
                : $cellValue;
        }

        return $result;
    }

    /**
     * Normalize a date cell to an ISO `Y-m-d` string the `date` validation rule
     * and Carbon::parse() both accept.
     *
     * Two shapes reach us, and BOTH used to break the import:
     *
     *  1. A real Excel date cell. Excel stores dates as a day serial, and the
     *     import objects passed to Excel::toArray() do not implement
     *     WithFormatData, so PhpSpreadsheet hands back the RAW value: typing
     *     15/01/2025 arrives here as 45672.0. Left alone it fails the `date`
     *     rule (strtotime('45672') === false), so every row of an otherwise
     *     valid file was blocked. Enabling WithFormatData globally was rejected
     *     as the fix: it would also format `monto`, turning a currency-styled
     *     cell into "$1,500.00" and breaking the `numeric` rule instead.
     *
     *  2. A date typed as TEXT in the day-first order used in Mexico. PHP reads
     *     a slash-separated date as m/d/Y, so '15/01/2025' is rejected outright
     *     and — worse — '01/03/2025' silently parses as January 3rd when the
     *     admin meant March 1st. Day-first is the documented convention here.
     *
     * Anything else is returned untouched so the validator stays the single
     * place that decides whether a value is an acceptable date.
     */
    private function normalizeDateCell(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Shape 1 — Excel day serial (int, float, or numeric string).
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $serial = (float) $value;

            if ($serial >= 1 && $serial <= self::MAX_EXCEL_DATE_SERIAL) {
                return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
            }

            return $value;
        }

        // Shape 2 — day-first text: 15/01/2025 or 15-01-2025. An ISO value
        // (2025-01-15) has a 4-digit head and never matches, so it falls
        // through unchanged.
        if (is_string($value) && preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $matches) === 1) {
            [, $day, $month, $year] = $matches;

            if (checkdate((int) $month, (int) $day, (int) $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return $value;
    }

    /**
     * Normalize a single cell value (trim strings, convert empty to null).
     */
    private function normalizeCell(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * Return true when every cell in the row is null or empty string.
     *
     * @param  array<int, mixed>  $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }
}
