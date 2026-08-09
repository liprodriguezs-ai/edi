<?php

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * ReportExport — generic Maatwebsite Excel export for any report.
 *
 * Accepts a pre-built array of rows + header row so any report can be exported
 * without touching this class.
 */
class ReportExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $headers,
        private readonly string $sheetTitle = 'Reporte'
    ) {}

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->headers;
    }

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31); // Excel sheet names limited to 31 chars
    }
}
