<?php

namespace Modules\DataImport\Services;

use Maatwebsite\Excel\Concerns\WithLimit;

/**
 * RowCapImport — inert "import placeholder" object passed as the first
 * argument to Excel::toArray() so maatwebsite/excel's ReaderFactory wires a
 * PhpSpreadsheet IReadFilter (Maatwebsite\Excel\Filters\LimitFilter) onto the
 * underlying reader BEFORE the workbook is parsed.
 *
 * This bounds memory at read time: rows beyond the limit are never turned
 * into PHP cell objects by PhpSpreadsheet's XML parser, unlike slicing the
 * resulting array AFTER Excel::toArray() returns — by then the whole file
 * would already have been materialized in memory.
 *
 * Used exclusively by WorkbookReader::parsePreview() for the synchronous
 * POST /import/validate dry-run (plan 059). WorkbookReader::parse(), used by
 * the EXECUTE path (RunImportJob), keeps passing a plain stdClass and stays
 * full-fidelity — this class must never be wired into that path.
 */
class RowCapImport implements WithLimit
{
    public function __construct(private readonly int $rowLimit) {}

    /**
     * Total spreadsheet rows readable, counting from row 1 (the header row).
     * Maatwebsite applies this as a LimitFilter(startRow: 1, limit: $rowLimit).
     */
    public function limit(): int
    {
        return $this->rowLimit;
    }
}
