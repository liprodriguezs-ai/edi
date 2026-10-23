<?php

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Already-projected Concentrado EDI / Reporte general sheet: headings plus
 * plain cell lines built by ChildReportSheet.
 */
class ChildReportExport implements FromArray, WithHeadings
{
    /**
     * @param  string[]  $headings
     * @param  array<int, array<int, mixed>>  $lines
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $lines,
    ) {}

    /**
     * @return string[]
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->lines;
    }
}
