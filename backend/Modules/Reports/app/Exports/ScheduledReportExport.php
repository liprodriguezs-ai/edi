<?php

namespace Modules\Reports\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Projects a report model's rows through its own reportColumns() contract.
 *
 * The label => accessor map lives on each model, so the export stays generic:
 * an accessor is either a column name or a closure receiving the row.
 */
class ScheduledReportExport implements FromArray, WithHeadings
{
    /**
     * @param  Collection<int, mixed>  $rows
     * @param  array<string, mixed>  $columns
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $columns,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_keys($this->columns);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->rows->map(function ($row): array {
            $line = [];

            foreach ($this->columns as $accessor) {
                $line[] = is_callable($accessor) ? $accessor($row) : ($row->{$accessor} ?? '');
            }

            return $line;
        })->all();
    }
}
